<?php

namespace App\Services\Legal;

use App\DTOs\ConfidenceResult;
use App\DTOs\ParsedQuery;
use App\DTOs\RetrievalResult;
use App\DTOs\TriageResult;
use App\Services\AI\CitationVerifierService;
use App\Services\AI\EvalJudgeService;
use App\Services\AI\LegalRuleExtractorService;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Services\AI\ConfidenceAssessor;
use App\Services\AI\EmbedCacheService;
use App\Services\AI\EvidenceBuilderService;
use App\Services\AI\HyDEService;
use App\Services\AI\OllamaEmbeddingService;
use App\Contracts\AnswerServiceInterface;
use App\Services\AI\QueryParserService;
use App\Services\AI\DecisionAuthorityScorer;
use App\Services\AI\RerankerService;
use App\Services\Chat\ChatTitleService;
use App\Services\Echr\EchrCitationBuilder;
use App\Services\Echr\EchrRetrieverService;
use App\Services\Legal\KnowledgeSourceRouter;
use App\Services\Legal\LegalTriageService;
use App\Services\Matsne\ArticleDetectorService;
use App\Services\Matsne\SemanticArticleRetrieverService;
use App\Services\Matsne\MatsneRetrieverService;
use App\Services\Legal\EuRetrieverService;
use App\Services\German\GermanRetrieverService;
use App\Services\ConstCourt\ConstCourtRetrieverService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LegalChatOrchestratorService
{
    public function __construct(
        private readonly EmbedCacheService          $embedCache,
        private readonly LegalCaseRetrieverService  $retriever,
        private readonly AnswerServiceInterface     $answerer,
        private readonly ChatTitleService           $titleService,
        private readonly QueryParserService         $queryParser,
        private readonly HyDEService                $hyde,
        private readonly ConfidenceAssessor         $confidenceAssessor,
        private readonly RerankerService            $reranker,
        private readonly EvidenceBuilderService     $evidenceBuilder,
        private readonly DecisionAuthorityScorer    $authorityScorer,
        private readonly KnowledgeSourceRouter      $sourceRouter,
        private readonly EchrRetrieverService       $echrRetriever,
        private readonly EchrCitationBuilder        $echrCitationBuilder,
        private readonly ArticleDetectorService           $articleDetector,
        private readonly SemanticArticleRetrieverService  $semanticArticleRetriever,
        private readonly MatsneRetrieverService           $matsneRetriever,
        private readonly EuRetrieverService         $euRetriever,
        private readonly GermanRetrieverService     $germanRetriever,
        private readonly ConstCourtRetrieverService $constCourtRetriever,
        private readonly OllamaEmbeddingService     $ollamaEmbedder,
        private readonly LegalTriageService         $triage,
        private readonly CitationVerifierService    $citationVerifier,
        private readonly EvalJudgeService           $evalJudge,
        private readonly LegalRuleExtractorService  $ruleExtractor,
    ) {}

    /**
     * Full pipeline (non-streaming): prepare → answer → finalize.
     */
    public function handle(Chat $chat, string $userQuestion, array $sources = ['court', 'matsne', 'eu', 'german', 'const_court']): array
    {
        $ctx = $this->prepare($chat, $userQuestion, $sources);

        $answerText = $this->answerer->answer(
            userQuestion:      $ctx['userQuestion'],
            decisions:         $ctx['finalDecisions'],
            historyMessages:   $ctx['history'],
            totalFound:        $ctx['retrieval']->totalMetaFound,
            mode:              $ctx['mode'],
            confidence:        $ctx['confidence'],
            lawResults:        [],
            echrResults:       $ctx['echrResults'],
            matsneResults:     $ctx['matsneResults']     ?? [],
            euResults:         $ctx['euResults']         ?? [],
            germanResults:     $ctx['germanResults']     ?? [],
            constCourtResults: $ctx['constCourtResults'] ?? [],
            sources:           $ctx['sources'],
            issueList:         $ctx['issueList'],
            triage:            $ctx['triageResult'],
            extractedRules:    $ctx['extractedRules']    ?? [],
        );

        $assistantMessage = $this->finalize($chat, $ctx, $answerText);

        return [
            'message'   => $assistantMessage,
            'retrieval' => $ctx['retrieval'],
        ];
    }

    /**
     * Runs all pipeline steps up to (but not including) the LLM answer call.
     * Returns a context array that can be passed to finalize() after streaming.
     */
    public function prepare(Chat $chat, string $userQuestion, array $sources = ['court', 'matsne', 'eu', 'german', 'const_court']): array
    {
        $startTime = microtime(true);

        // ── 1. Save user message [DB] ─────────────────────────────────────────
        $userMessage = ChatMessage::create([
            'chat_id' => $chat->id,
            'role'    => 'user',
            'content' => $userQuestion,
        ]);

        // ── 2. Auto-set chat title [DB] ───────────────────────────────────────
        if (is_null($chat->title)) {
            $chat->update(['title' => $this->titleService->generateFromMessage($userQuestion)]);
        }

        // ── 3+4. Triage — intent + mode + issue spotting + domain + source plan ─
        $triageResult = $this->triage->triage($userQuestion, $sources);
        $intent       = $triageResult->intent;
        $mode         = $triageResult->mode;
        $issueList    = $triageResult->issueList;

        Log::debug('Orchestrator: triage', $triageResult->toDebugArray());

        // ── 5. Retrieval pipeline ─────────────────────────────────────────────
        [$retrieval, $parsedQuery, $debugFlags, $searchTerms, $ollamaEmbedding] = $this->runPipeline($triageResult, $userQuestion, $sources);

        // ── 5. Confidence assessment ──────────────────────────────────────────
        $confidence = $this->confidenceAssessor->assess($retrieval);

        Log::debug('Orchestrator: confidence', [
            'label'       => $confidence->label,
            'score'       => $confidence->score,
            'explanation' => $confidence->explanation,
        ]);

        // ── 6a. Authority scoring — court level + year + joint panel ─────────
        $scoredDecisions = $this->authorityScorer->score($retrieval->decisions, $mode);

        $debugFlags['authority_scored'] = !empty($scoredDecisions);
        $debugFlags['outliers_flagged'] = count(array_filter(
            $scoredDecisions,
            fn($d) => in_array('outlier', $d['quality_flags'] ?? [])
        ));
        $debugFlags['trend_detected']   = !empty(array_filter(
            $scoredDecisions,
            fn($d) => in_array('trend_shift', $d['quality_flags'] ?? [])
        ));

        // ── 6b. Rerank candidates → top K ────────────────────────────────────
        $topK           = (int) config('openai.retrieval_case_limit', 3);
        $initialCount   = count($scoredDecisions);
        $finalDecisions = $this->reranker->rerank($userQuestion, $scoredDecisions, $topK, $mode);

        $debugFlags['reranked']                 = count($finalDecisions) !== $initialCount && $initialCount > $topK;
        $debugFlags['initial_candidates_count'] = $initialCount;
        $debugFlags['final_candidates_count']   = count($finalDecisions);

        // ── 7. Evidence annotations ───────────────────────────────────────────
        $enrichedDecisions = $this->evidenceBuilder->build($userQuestion, $finalDecisions);

        // ── 8. Source retrieval — driven by TriageResult ──────────────────────
        $sourcePlan        = $this->sourceRouter->plan($parsedQuery ?? $userQuestion);
        $echrResults       = [];
        $matsneResults     = [];
        $euResults         = [];
        $germanResults     = [];
        $constCourtResults = [];

        if (!$triageResult->isChatOnly()) {
            $lawDomains = $triageResult->domains;

            // ── Article detector first — no Ollama needed (direct LIKE query) ─
            try {
                $matsneResults = $this->articleDetector->detect($userQuestion, $lawDomains);
                if (!empty($matsneResults)) {
                    Log::warning('Orchestrator: article detector hit', ['count' => count($matsneResults)]);
                }
            } catch (\Throwable $e) {
                Log::warning('Orchestrator: article detector failed', ['error' => $e->getMessage()]);
            }

            // ── Semantic article retriever — universal supplement ──────────────
            // Always runs (when domain is known) to retrieve articles the user didn't
            // explicitly cite but are semantically relevant (e.g. limitation articles
            // alongside substantive defect articles). Domain filter prevents cross-domain
            // noise. ArticleDetector hits (similarity 0.95) dominate the 6-article cap.
            $relevantYear = $triageResult->temporalYear ?? (int) date('Y');
            if (!empty($ollamaEmbedding) && !empty($lawDomains)) {
                try {
                    $semanticArticles = $this->semanticArticleRetriever->retrieve($ollamaEmbedding, $lawDomains, relevantYear: $relevantYear);
                    if (!empty($semanticArticles)) {
                        $existingKeys = [];
                        foreach ($matsneResults as $r) {
                            $existingKeys["{$r['matsne_id']}:" . substr($r['excerpt'], 0, 40)] = true;
                        }
                        foreach ($semanticArticles as $sa) {
                            $k = "{$sa['matsne_id']}:" . substr($sa['excerpt'], 0, 40);
                            if (!isset($existingKeys[$k])) {
                                $matsneResults[]  = $sa;
                                $existingKeys[$k] = true;
                            }
                        }

                        // Cap: article_detector(0.95) > semantic(similarity) — top 6
                        if (count($matsneResults) > 6) {
                            usort($matsneResults, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
                            $matsneResults = array_slice($matsneResults, 0, 6);
                        }

                        Log::info('Orchestrator: semantic article retriever injected', [
                            'semantic_count' => count($semanticArticles),
                            'total_matsne'   => count($matsneResults),
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Orchestrator: semantic article retriever failed', ['error' => $e->getMessage()]);
                }
            }

            // ── Ollama embedding (shared across matsne/eu/german/const_court) ─
            $needsMatsneSemantic = $triageResult->needsNorms && empty($matsneResults);
            $needsOllama = $needsMatsneSemantic
                || $triageResult->needsConstCourt
                || $triageResult->needsEu
                || $triageResult->needsGerman;

            if ($needsOllama && empty($ollamaEmbedding)) {
                try {
                    $ollamaText     = $searchTerms ?: $userQuestion;
                    $ollamaCacheKey = 'ollama_embed_' . md5($ollamaText . config('ollama.embedding_model', 'bge-m3'));
                    $ollamaEmbedding = Cache::remember($ollamaCacheKey, 86400, fn() => $this->matsneRetriever->embedQuery($ollamaText));
                    Log::debug('Orchestrator: shared bge-m3 embedding ready');
                } catch (\Throwable $e) {
                    Log::warning('Orchestrator: Ollama busy, skipping bge-m3 sources', ['error' => $e->getMessage()]);
                    $ollamaEmbedding = [];
                }
            }

            // ── Matsne semantic search — skipped when article detector found results ─
            if ($needsMatsneSemantic && !empty($ollamaEmbedding)) {
                try {
                    $matsneResults = $this->matsneRetriever->retrieve(
                        $searchTerms ?: $userQuestion,
                        embedding:    $ollamaEmbedding,
                        domains:      $lawDomains,
                        relevantYear: $relevantYear,
                    );
                    Log::debug('Orchestrator: matsne retrieval', [
                        'count'   => count($matsneResults),
                        'domains' => $lawDomains,
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('Orchestrator: matsne retrieval failed', ['error' => $e->getMessage()]);
                }
            }

            if ($triageResult->needsEu && !empty($ollamaEmbedding)) {
                try {
                    $euResults = $this->euRetriever->retrieve($searchTerms ?: $userQuestion, embedding: $ollamaEmbedding);
                    Log::debug('Orchestrator: EU retrieval', ['count' => count($euResults)]);
                } catch (\Throwable $e) {
                    Log::warning('Orchestrator: EU retrieval failed', ['error' => $e->getMessage()]);
                }
            }

            if ($triageResult->needsGerman && !empty($ollamaEmbedding)) {
                try {
                    $germanResults = $this->germanRetriever->retrieve($searchTerms ?: $userQuestion, embedding: $ollamaEmbedding);
                    Log::debug('Orchestrator: german retrieval', ['count' => count($germanResults)]);
                } catch (\Throwable $e) {
                    Log::warning('Orchestrator: german retrieval failed', ['error' => $e->getMessage()]);
                }
            }

            if ($triageResult->needsConstCourt && !empty($ollamaEmbedding)) {
                try {
                    $constCourtResults = $this->constCourtRetriever->retrieve($searchTerms ?: $userQuestion, $ollamaEmbedding);
                    Log::debug('Orchestrator: const_court retrieval', ['count' => count($constCourtResults)]);
                } catch (\Throwable $e) {
                    Log::warning('Orchestrator: const_court retrieval failed', ['error' => $e->getMessage()]);
                }
            }
        }

        // ── 9. Rule extraction — Stage 1 of two-stage answering ──────────────
        // Extracts concrete rules (deadlines, thresholds) from retrieved matsne articles
        // so the answer generator doesn't default to training priors.
        $extractedRules = [];
        if (!empty($matsneResults)) {
            try {
                $extractedRules = $this->ruleExtractor->extract($userQuestion, $matsneResults);
            } catch (\Throwable $e) {
                Log::warning('Orchestrator: rule extractor failed', ['error' => $e->getMessage()]);
            }
        }

        // ── 10. Conversation history ──────────────────────────────────────────
        $history = $this->buildHistory($chat, $userMessage->id);

        return [
            'startTime'      => $startTime,
            'userMessage'    => $userMessage,
            'userQuestion'   => $userQuestion,
            'intent'         => $intent,
            'mode'           => $mode,
            'triageResult'   => $triageResult,
            'retrieval'      => $retrieval,
            'parsedQuery'    => $parsedQuery,
            'debugFlags'     => $debugFlags,
            'confidence'     => $confidence,
            'finalDecisions' => $enrichedDecisions,
            'echrResults'    => $echrResults,
            'matsneResults'  => $matsneResults,
            'euResults'          => $euResults,
            'germanResults'      => $germanResults,
            'constCourtResults'  => $constCourtResults,
            'sourcePlan'         => $sourcePlan,
            'sources'            => $sources,
            'history'            => $history,
            'issueList'          => $issueList,
            'extractedRules'     => $extractedRules,
        ];
    }

    /**
     * Saves the assistant message to the DB after the answer text is known.
     */
    public function finalize(Chat $chat, array $ctx, string $answerText): ChatMessage
    {
        $citations           = $this->buildCitations($ctx['finalDecisions']);
        $echrCitations       = $this->echrCitationBuilder->build($ctx['echrResults'] ?? []);
        $matsneCitations     = $this->buildMatsneCitations($ctx['matsneResults']     ?? []);
        $euCitations         = $this->buildEuCitations($ctx['euResults']             ?? []);
        $germanCitations     = $this->buildGermanCitations($ctx['germanResults']     ?? []);
        $constCourtCitations = $this->buildConstCourtCitations($ctx['constCourtResults'] ?? []);

        $issueTracking  = $this->detectAddressedIssues($ctx['issueList'], $answerText);
        $strategyText   = $ctx['mode'] === 'advocate'
            ? $this->extractStrategySection($answerText)
            : null;

        // ── Citation verification ─────────────────────────────────────────────
        $citationCheck = $this->citationVerifier->verify(
            $answerText,
            $ctx['finalDecisions'],
            $ctx['matsneResults'] ?? [],
        );

        $domesticConfidence = $ctx['confidence']->label;
        $matsneConfidence   = !empty($ctx['matsneResults']) ? 'high' : 'none';
        $echrConfidence     = !empty($ctx['echrResults'])   ? 'high' : 'none';

        $overallConfidence = $domesticConfidence;
        if ($overallConfidence === 'none' && ($matsneConfidence === 'high' || $echrConfidence === 'high')) {
            $overallConfidence = 'medium';
        }

        return ChatMessage::create([
            'chat_id' => $chat->id,
            'role'    => 'assistant',
            'content' => $answerText,
            'meta'    => [
                // ── Evidence (structured) ─────────────────────────────────────
                'evidence' => [
                    'domestic_cases' => $citations,
                    'echr_cases'     => $echrCitations,
                    'matsne_docs'    => $matsneCitations,
                    'eu_docs'        => $euCitations,
                    'german_cases'   => $germanCitations,
                    'const_court'    => $constCourtCitations,
                ],

                // ── Confidence breakdown ──────────────────────────────────────
                'matsne_confidence'   => $matsneConfidence,
                'domestic_confidence' => $domesticConfidence,
                'echr_confidence'     => $echrConfidence,
                'overall_confidence'  => $overallConfidence,

                // ── Backward-compatible flat fields ───────────────────────────
                'citations'            => $citations,
                'echr_citations'       => $echrCitations,
                'matsne_citations'     => $matsneCitations,
                'eu_citations'         => $euCitations,
                'german_citations'      => $germanCitations,
                'const_court_citations' => $constCourtCitations,
                'confidence'            => $overallConfidence,
                'confidence_score'     => $ctx['confidence']->score,
                'confidence_note'      => $ctx['confidence']->explanation,

                // ── Retrieval metadata ────────────────────────────────────────
                'retrieval_mode'       => $this->resolveMode($ctx['intent'], $ctx['retrieval']),
                'answer_mode'          => $ctx['mode'],
                'sources_active'       => $ctx['sourcePlan']->sourcesActive(),
                'matched_case_ids'     => $ctx['retrieval']->matchedCaseIds,
                'matched_case_numbers' => $ctx['retrieval']->matchedCaseNumbers,
                'relevance_scores'     => $ctx['retrieval']->relevanceScores,
                'used_chunk_count'     => $ctx['retrieval']->usedChunkCount,
                'used_case_count'      => count($ctx['finalDecisions']),
                'total_meta_found'     => $ctx['retrieval']->totalMetaFound,
                'search_query_used'    => $ctx['parsedQuery']?->terms ?? $ctx['userQuestion'],
                'parsed_filters'       => $ctx['parsedQuery']?->toArray() ?? [],
                'pipeline_ms'          => (int) ((microtime(true) - $ctx['startTime']) * 1000),
                'debug'                => $ctx['debugFlags'],

                // ── Issue Spotter ─────────────────────────────────────────────
                'issues'               => $ctx['issueList']->toArray(),
                'issues_count'         => $ctx['issueList']->issueCount,
                'issues_domains'       => $ctx['issueList']->domains(),
                'issues_addressed'     => $issueTracking['addressed'],
                'issues_missed'        => $issueTracking['missed'],
                'issues_coverage_pct'  => $issueTracking['coverage_pct'],

                // ── Advocate strategy ─────────────────────────────────────────
                'strategy'             => $strategyText,

                // ── Citation verification ─────────────────────────────────────
                'citation_check' => [
                    'flagged'            => $citationCheck['flagged'],
                    'valid'              => $citationCheck['valid'],
                    'hallucinated'       => $citationCheck['hallucinated'],
                    'hallucinated_count' => $citationCheck['hallucinated_count'],
                ],

                // ── LLM-as-Judge evaluation (filled later via runEval) ───────
                'eval' => null,
            ],
        ]);
    }

    /**
     * Runs the LLM-as-Judge evaluation AFTER the done event is sent,
     * updates the message meta, and returns the result for the SSE eval event.
     */
    public function runEval(ChatMessage $message, array $ctx, string $answerText): ?array
    {
        if (!config('openai.judge_enabled', false) || ($ctx['mode'] ?? 'explain') === 'chat') {
            return null;
        }

        try {
            $evalResult = $this->evalJudge->evaluate(
                userQuestion:  $ctx['userQuestion'],
                answerText:    $answerText,
                mode:          $ctx['mode'],
                decisions:     $ctx['finalDecisions'],
                matsneResults: $ctx['matsneResults'] ?? [],
            );

            Log::info('EvalJudge: completed', [
                'verdict' => $evalResult['verdict'] ?? null,
                'overall' => $evalResult['overall'] ?? null,
                'mode'    => $ctx['mode'],
            ]);

            $meta         = $message->meta ?? [];
            $meta['eval'] = $evalResult;
            $message->update(['meta' => $meta]);

            return $evalResult;

        } catch (\Throwable $e) {
            Log::warning('EvalJudge: skipped — ' . $e->getMessage());
            return null;
        }
    }

    // ── Issue coverage tracking ───────────────────────────────────────────────

    /**
     * Checks which issues from the IssueList appear in the answer text.
     *
     * Strategy: for each issue, look for its title words OR any of its keywords
     * in the answer (case-insensitive). An issue is "addressed" when at least
     * one signal matches.
     *
     * Returns:
     *   addressed     → 1-based issue numbers found in the answer
     *   missed        → 1-based issue numbers NOT found
     *   coverage_pct  → 0-100 integer
     */
    private function detectAddressedIssues(\App\DTOs\IssueList $issueList, string $answerText): array
    {
        if ($issueList->issueCount === 0) {
            return ['addressed' => [], 'missed' => [], 'coverage_pct' => 100];
        }

        $lower      = mb_strtolower($answerText);
        $addressed  = [];
        $missed     = [];

        foreach ($issueList->issues as $i => $issue) {
            $num    = $i + 1;
            $found  = false;

            // Check title words (each word >= 4 chars)
            $titleWords = preg_split('/\s+/u', mb_strtolower($issue->title));
            foreach ($titleWords as $word) {
                if (mb_strlen($word) >= 4 && str_contains($lower, $word)) {
                    $found = true;
                    break;
                }
            }

            // Check keywords
            if (!$found) {
                foreach ($issue->keywords as $kw) {
                    if (mb_strlen($kw) >= 3 && str_contains($lower, mb_strtolower($kw))) {
                        $found = true;
                        break;
                    }
                }
            }

            if ($found) {
                $addressed[] = $num;
            } else {
                $missed[] = $num;
            }
        }

        $pct = (int) round(count($addressed) / $issueList->issueCount * 100);

        if (!empty($missed)) {
            Log::info('Orchestrator: issues missed in answer', [
                'missed'       => $missed,
                'coverage_pct' => $pct,
            ]);
        }

        return ['addressed' => $addressed, 'missed' => $missed, 'coverage_pct' => $pct];
    }

    /**
     * Extracts the 💡 სტრატეგია section from an advocate-mode answer.
     * Returns the section text or null if not found.
     */
    private function extractStrategySection(string $answerText): ?string
    {
        // Match "💡 სტრატეგია" heading and capture everything after it
        if (preg_match('/💡\s*სტრატეგია[^\n]*\n+(.*)/su', $answerText, $m)) {
            $section = trim($m[1]);
            // Stop at next emoji-headed section if present
            $section = preg_split('/\n+[💡⚖️🔍✅📌📕]\s/u', $section)[0];
            return trim($section) ?: null;
        }

        return null;
    }

    // ── Pipeline ──────────────────────────────────────────────────────────────

    private function runPipeline(TriageResult $triage, string $userQuestion, array $sources = []): array
    {
        $debugFlags = [
            'hyde_used'          => false,
            'raw_embedding_used' => true,
            'retrieval_strategy' => 'none',
            'domain_classified'  => $triage->caseType,
            'case_type_filter'   => $triage->caseTypeFilter(),
        ];

        if ($triage->isChatOnly()) {
            return [RetrievalResult::empty(), null, $debugFlags, null, null];
        }

        // No court source — skip court retrieval, still need parsedQuery for matsne routing
        if (!$triage->needsCases) {
            $parsedQuery = $this->queryParser->parse($userQuestion, $triage->searchQuery);
            $debugFlags['retrieval_strategy'] = 'norms_only';
            return [RetrievalResult::empty(), $parsedQuery, $debugFlags, $triage->searchQuery, null];
        }

        $parsedQuery = $this->queryParser->parse($userQuestion, $triage->searchQuery);

        Log::debug('Orchestrator: parsed query', [
            'terms'     => $parsedQuery->terms,
            'case_type' => $triage->caseTypeFilter(),
            'filters'   => $parsedQuery->toArray(),
        ]);

        // Ollama bge-m3 embedding (cached by search query)
        $ollamaText      = $triage->searchQuery ?: $userQuestion;
        $ollamaCacheKey  = 'ollama_embed_' . md5($ollamaText . config('ollama.embedding_model', 'bge-m3'));
        $ollamaEmbedding = Cache::remember($ollamaCacheKey, 86400, fn() => $this->ollamaEmbedder->embed($ollamaText));

        // Fingerprint embedding — long queries (>500 chars) may be pasted decision text.
        // Embed the first 300 chars to detect near-duplicate chunks at threshold 0.90.
        $fingerprintEmbedding = null;
        if (mb_strlen($userQuestion) > 500) {
            $fpText = mb_substr($userQuestion, 0, 300);
            $fpKey  = 'fp_embed_' . md5($fpText . config('ollama.embedding_model', 'bge-m3'));
            $fingerprintEmbedding = Cache::remember($fpKey, 86400, fn() => $this->ollamaEmbedder->embed($fpText));
        }

        $strategy = $parsedQuery->hasCaseNumber() ? 'case_number+vector' : 'vector+metadata';
        $debugFlags['retrieval_strategy'] = $strategy;

        $retrieval = $this->retriever->retrieve(
            rawEmbedding:         $ollamaEmbedding,
            searchTerms:          $parsedQuery->terms,
            originalQuery:        $userQuestion,
            hydeEmbedding:        null,
            parsed:               $parsedQuery,
            caseType:             $triage->caseTypeFilter(),
            fingerprintEmbedding: $fingerprintEmbedding,
        );

        return [$retrieval, $parsedQuery, $debugFlags, $triage->searchQuery, $ollamaEmbedding];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function buildHistory(Chat $chat, int $beforeMessageId): array
    {
        $limit = config('openai.context_history_messages', 6);

        return ChatMessage::where('chat_id', $chat->id)
            ->where('id', '<', $beforeMessageId)
            ->whereIn('role', ['user', 'assistant'])
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->reverse()
            ->map(fn($m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->toArray();
    }

    private function resolveMode(string $intent, RetrievalResult $retrieval): string
    {
        if ($intent === 'chat') {
            return 'chat';
        }
        return $retrieval->isEmpty() ? 'no_results' : 'grounded';
    }

    private function buildMatsneCitations(array $matsneResults): array
    {
        return array_map(fn(array $r) => [
            'type'               => 'matsne',
            'matsne_id'          => $r['matsne_id'],
            'title'              => $r['title'],
            'doc_type'           => $r['doc_type'],
            'issuer'             => $r['issuer'],
            'is_active'          => $r['is_active'],
            'effective_from_year'=> $r['effective_from_year'],
            'effective_to_year'  => $r['effective_to_year'],
            'excerpt'            => $r['excerpt'],
            'similarity'         => $r['similarity'],
            'url'                => $r['url'],
        ], $matsneResults);
    }

    private function buildEuCitations(array $euResults): array
    {
        return array_map(fn(array $r) => [
            'type'       => 'eu',
            'cellar_id'  => $r['cellar_id'],
            'doc_type'   => $r['doc_type'],
            'source'     => $r['source'],
            'court'      => $r['court'],
            'case_num'   => $r['case_num'],
            'title'      => $r['title'],
            'doc_date'   => $r['doc_date'],
            'excerpt'    => $r['excerpt'],
            'similarity' => $r['similarity'],
            'url'        => $r['url'],
        ], $euResults);
    }

    private function buildConstCourtCitations(array $constCourtResults): array
    {
        return array_map(fn(array $r) => [
            'type'          => 'const_court',
            'legal_id'      => $r['legal_id'],
            'case_number'   => $r['case_number'],
            'case_name'     => $r['case_name'],
            'decision_type' => $r['decision_type'],
            'decision_date' => $r['decision_date'],
            'college'       => $r['college'],
            'respondent'    => $r['respondent'],
            'result'        => $r['result'],
            'excerpt'       => $r['excerpt'],
            'similarity'    => $r['score'],
            'url'           => $r['url'],
        ], $constCourtResults);
    }

    private function buildGermanCitations(array $germanResults): array
    {
        return array_map(fn(array $r) => [
            'type'            => 'german',
            'case_id'         => $r['case_id'],
            'external_id'     => $r['external_id'],
            'court_name'      => $r['court_name'],
            'level_of_appeal' => $r['level_of_appeal'],
            'jurisdiction'    => $r['jurisdiction'],
            'date_year'       => $r['date_year'],
            'excerpt'         => $r['excerpt'],
            'similarity'      => $r['similarity'],
        ], $germanResults);
    }

    private function buildCitations(array $decisions): array
    {
        return array_map(function (array $d) {
            return [
                'case_id'         => $d['case_id'],
                'case_num'        => $d['case_num'],
                'case_date'       => $d['case_date'] instanceof \Carbon\Carbon
                                        ? $d['case_date']->format('Y-m-d')
                                        : $d['case_date'],
                'court'           => $d['court'],
                'chamber'         => $d['chamber'],
                'category'        => $d['category'],
                'dispute_subject' => $d['dispute_subject'],
                'result'          => $d['result'],
                'relevance_score' => $d['relevance_score'],
                'case_type'       => $d['case_type'] ?? 'administrative',
                'url'             => "/fullcase/{$d['case_type']}/{$d['case_id']}",
            ];
        }, $decisions);
    }
}
