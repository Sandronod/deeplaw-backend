<?php

namespace App\Services\Legal;

use App\DTOs\ConfidenceResult;
use App\DTOs\ParsedQuery;
use App\DTOs\RetrievalResult;
use App\DTOs\TriageResult;
use App\Services\AI\AnswerValidatorService;
use App\Services\AI\AnswerPostProcessorService;
use App\Services\AI\CitationVerifierService;
use App\Services\AI\EvalJudgeService;
use App\Services\AI\LegalRuleExtractorService;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Services\AI\ConfidenceAssessor;
use App\Services\AI\EmbedCacheService;
use App\Services\AI\EvidenceBuilderService;
use App\Services\AI\CaseRelevanceScorerService;
use App\Services\AI\HyDEService;
use App\Services\AI\OllamaEmbeddingService;
use App\Services\AI\OpenAIEmbeddingService;
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
    private const SUPPORTED_SOURCES = ['court', 'matsne', 'echr', 'eu', 'german', 'const_court'];

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
        private readonly CaseRelevanceScorerService $caseRelevanceScorer,
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
        private readonly OpenAIEmbeddingService     $openAiEmbedder,
        private readonly LegalTriageService         $triage,
        private readonly CitationVerifierService    $citationVerifier,
        private readonly AnswerValidatorService     $answerValidator,
        private readonly AnswerPostProcessorService $answerPostProcessor,
        private readonly EvalJudgeService           $evalJudge,
        private readonly LegalRuleExtractorService  $ruleExtractor,
    ) {}

    private function normalizeSources(array $sources): array
    {
        $selected = $sources ?: (array) config('openai.default_sources', ['court', 'matsne']);
        $selected = array_values(array_unique(array_filter(
            array_map('strval', $selected),
            fn (string $source) => in_array($source, self::SUPPORTED_SOURCES, true),
        )));

        return $selected ?: ['court', 'matsne'];
    }

    /**
     * Full pipeline (non-streaming): prepare → answer → finalize.
     */
    public function handle(Chat $chat, string $userQuestion, array $sources = []): array
    {
        $ctx = $this->prepare($chat, $userQuestion, $sources);

        $answerStartedAt = microtime(true);
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
        $ctx['timings_ms']['answer_generation'] = $this->elapsedMs($answerStartedAt);

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
    public function prepare(Chat $chat, string $userQuestion, array $sources = []): array
    {
        $sources = $this->normalizeSources($sources);

        $startTime = microtime(true);
        $timings = [];

        // ── 1. Save user message [DB] ─────────────────────────────────────────
        $stageStartedAt = microtime(true);
        $userMessage = ChatMessage::create([
            'chat_id' => $chat->id,
            'role'    => 'user',
            'content' => $userQuestion,
        ]);
        $timings['save_user_message'] = $this->elapsedMs($stageStartedAt);

        // ── 2. Auto-set chat title [DB] ───────────────────────────────────────
        $stageStartedAt = microtime(true);
        if (is_null($chat->title)) {
            $chat->update(['title' => $this->titleService->generateFromMessage($userQuestion)]);
        }
        $timings['title_update'] = $this->elapsedMs($stageStartedAt);

        // ── 3+4. Triage — intent + mode + issue spotting + domain + source plan ─
        $stageStartedAt = microtime(true);
        $triageResult = $this->triage->triage($userQuestion, $sources);
        $intent       = $triageResult->intent;
        $mode         = $triageResult->mode;
        $issueList    = $triageResult->issueList;
        $timings['triage'] = $this->elapsedMs($stageStartedAt);

        Log::debug('Orchestrator: triage', $triageResult->toDebugArray());

        // ── 5. Retrieval pipeline ─────────────────────────────────────────────
        $stageStartedAt = microtime(true);
        [$retrieval, $parsedQuery, $debugFlags, $searchTerms, $ollamaEmbedding, $pipelineTimings] = $this->runPipeline($triageResult, $userQuestion, $sources);
        $timings['court_pipeline_total'] = $this->elapsedMs($stageStartedAt);
        $timings['court_pipeline'] = $pipelineTimings;
        $courtRankingQuery = $this->courtRankingQuery($triageResult, $searchTerms, $userQuestion);
        $debugFlags['court_ranking_query'] = mb_substr($courtRankingQuery, 0, 500);
        $debugFlags['court_ranking_query_chars'] = mb_strlen($courtRankingQuery);

        // ── 5. Confidence assessment ──────────────────────────────────────────
        $stageStartedAt = microtime(true);
        $confidence = $this->confidenceAssessor->assess($retrieval);
        $timings['confidence_assessment'] = $this->elapsedMs($stageStartedAt);

        Log::debug('Orchestrator: confidence', [
            'label'       => $confidence->label,
            'score'       => $confidence->score,
            'explanation' => $confidence->explanation,
        ]);

        // ── 6a. Authority scoring — court level + year + joint panel ─────────
        $stageStartedAt = microtime(true);
        $scoredDecisions = $this->authorityScorer->score($retrieval->decisions, $mode);
        $timings['authority_scoring'] = $this->elapsedMs($stageStartedAt);

        $stageStartedAt = microtime(true);
        $scoredDecisions = $this->caseRelevanceScorer->score($courtRankingQuery, $scoredDecisions, $issueList);
        $timings['semantic_relevance_scoring'] = $this->elapsedMs($stageStartedAt);

        $debugFlags['authority_scored'] = !empty($scoredDecisions);
        $debugFlags['semantic_relevance_scored'] = !empty($scoredDecisions);
        $debugFlags['top_semantic_case'] = $scoredDecisions[0]['case_num'] ?? null;
        $debugFlags['top_semantic_score'] = $scoredDecisions[0]['semantic_relevance_score'] ?? null;
        $debugFlags['outliers_flagged'] = count(array_filter(
            $scoredDecisions,
            fn($d) => in_array('outlier', $d['quality_flags'] ?? [])
        ));
        $debugFlags['trend_detected']   = !empty(array_filter(
            $scoredDecisions,
            fn($d) => in_array('trend_shift', $d['quality_flags'] ?? [])
        ));

        // ── 6b. Rerank candidates → top K ────────────────────────────────────
        $topK           = max(
            (int) config('openai.retrieval_case_limit', 3),
            (int) config('openai.answer_case_limit', 5),
        );
        $initialCount   = count($scoredDecisions);
        $stageStartedAt = microtime(true);
        $finalDecisions = $this->annotateDecisionRoles(
            $this->reranker->rerank($courtRankingQuery, $scoredDecisions, $topK, $mode),
        );
        $timings['rerank'] = $this->elapsedMs($stageStartedAt);

        $debugFlags['reranked']                 = count($finalDecisions) !== $initialCount && $initialCount > $topK;
        $debugFlags['initial_candidates_count'] = $initialCount;
        $debugFlags['final_candidates_count']   = count($finalDecisions);
        $debugFlags['primary_case_count']       = count(array_filter($finalDecisions, fn (array $d) => ($d['answer_role'] ?? null) === 'primary'));
        $debugFlags['supporting_case_count']    = count(array_filter($finalDecisions, fn (array $d) => ($d['answer_role'] ?? null) === 'supporting'));

        // ── 7. Evidence annotations ───────────────────────────────────────────
        $stageStartedAt = microtime(true);
        $enrichedDecisions = $this->evidenceBuilder->build($userQuestion, $finalDecisions);
        $timings['evidence_build'] = $this->elapsedMs($stageStartedAt);

        // ── 8. Source retrieval — driven by TriageResult ──────────────────────
        $stageStartedAt = microtime(true);
        $sourcePlan        = $this->sourceRouter->plan($parsedQuery ?? $userQuestion);
        $timings['source_plan'] = $this->elapsedMs($stageStartedAt);
        $echrResults       = [];
        $matsneResults     = [];
        $euResults         = [];
        $germanResults     = [];
        $constCourtResults = [];
        $sourcesStartedAt  = microtime(true);

        if (!$triageResult->isChatOnly()) {
            $lawDomains = $triageResult->domains;
            $wantsMatsne     = empty($sources) || in_array('matsne', $sources, true);
            $wantsEchr       = empty($sources) || in_array('echr', $sources, true);
            $wantsEu         = empty($sources) || in_array('eu', $sources, true);
            $wantsGerman     = empty($sources) || in_array('german', $sources, true);
            $wantsConstCourt = empty($sources) || in_array('const_court', $sources, true);

            if ($wantsEchr && $sourcePlan->useEchr && $parsedQuery !== null) {
                try {
                    $sourceStartedAt = microtime(true);
                    $echrText = $searchTerms ?: $userQuestion;
                    $echrEmbedding = Cache::remember(
                        'echr_embed_' . md5($echrText . config('openai.embedding_model')),
                        86400,
                        fn () => $this->openAiEmbedder->embed($echrText)
                    );
                    $echrResults = $this->echrRetriever->retrieve(
                        $echrEmbedding,
                        $userQuestion,
                        $parsedQuery,
                    );
                    $timings['echr_retrieval'] = $this->elapsedMs($sourceStartedAt);
                    Log::debug('Orchestrator: ECHR retrieval', ['count' => count($echrResults)]);
                } catch (\Throwable $e) {
                    $timings['echr_retrieval'] = $this->elapsedMs($sourceStartedAt ?? microtime(true));
                    Log::warning('Orchestrator: ECHR retrieval failed', ['error' => $e->getMessage()]);
                }
            }

            if ($wantsMatsne && $triageResult->needsNorms) {
                // ── Article detector first — no Ollama needed (direct LIKE query) ─
                try {
                    $sourceStartedAt = microtime(true);
                    $matsneResults = $this->articleDetector->detect(
                        $userQuestion,
                        $lawDomains,
                        $triageResult->temporalYear ?? (int) date('Y'),
                    );
                    $timings['article_detector'] = $this->elapsedMs($sourceStartedAt);
                    if (!empty($matsneResults)) {
                        Log::warning('Orchestrator: article detector hit', ['count' => count($matsneResults)]);
                    }
                } catch (\Throwable $e) {
                    $timings['article_detector'] = $this->elapsedMs($sourceStartedAt ?? microtime(true));
                    Log::warning('Orchestrator: article detector failed', ['error' => $e->getMessage()]);
                }
            }

            $useSemanticNormSupplement = $this->shouldUseSemanticNormSupplement($triageResult, $matsneResults, $userQuestion);
            if (!$useSemanticNormSupplement) {
                $debugFlags['semantic_norm_supplement_skipped'] = true;
                $timings['norm_embedding'] = 0;
                $timings['semantic_article_retrieval'] = 0;
            }

            // ── Semantic article retriever — universal supplement ──────────────
            // Always runs (when domain is known) to retrieve articles the user didn't
            // explicitly cite but are semantically relevant (e.g. limitation articles
            // alongside substantive defect articles). Domain filter prevents cross-domain
            // noise. ArticleDetector hits (similarity 0.95) dominate the 6-article cap.
            $relevantYear = $triageResult->temporalYear ?? (int) date('Y');
            if ($useSemanticNormSupplement && $wantsMatsne && $triageResult->needsNorms && !empty($lawDomains) && empty($ollamaEmbedding)) {
                try {
                    $sourceStartedAt = microtime(true);
                    $ollamaText = $searchTerms ?: $userQuestion;
                    $ollamaCacheKey = 'ollama_embed_' . md5($ollamaText . config('ollama.embedding_model', 'bge-m3'));
                    $ollamaEmbedding = Cache::remember(
                        $ollamaCacheKey,
                        86400,
                        fn () => $this->matsneRetriever->embedQuery($ollamaText)
                    );
                    $timings['norm_embedding'] = $this->elapsedMs($sourceStartedAt);
                    Log::debug('Orchestrator: norm embedding ready');
                } catch (\Throwable $e) {
                    $timings['norm_embedding'] = $this->elapsedMs($sourceStartedAt ?? microtime(true));
                    Log::warning('Orchestrator: norm embedding failed', ['error' => $e->getMessage()]);
                    $ollamaEmbedding = [];
                }
            }

            if ($useSemanticNormSupplement && $wantsMatsne && !empty($ollamaEmbedding) && !empty($lawDomains)) {
                try {
                    $sourceStartedAt = microtime(true);
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
                    $timings['semantic_article_retrieval'] = $this->elapsedMs($sourceStartedAt);
                } catch (\Throwable $e) {
                    $timings['semantic_article_retrieval'] = $this->elapsedMs($sourceStartedAt ?? microtime(true));
                    Log::warning('Orchestrator: semantic article retriever failed', ['error' => $e->getMessage()]);
                }
            }

            // ── Ollama embedding (shared across matsne/eu/german/const_court) ─
            $needsMatsneSemantic = $useSemanticNormSupplement && $wantsMatsne && $triageResult->needsNorms && empty($matsneResults);
            $needsOllama = $needsMatsneSemantic
                || ($wantsConstCourt && $triageResult->needsConstCourt)
                || ($wantsEu && $triageResult->needsEu)
                || ($wantsGerman && $triageResult->needsGerman);

            if ($needsOllama && empty($ollamaEmbedding)) {
                try {
                    $sourceStartedAt = microtime(true);
                    $ollamaText     = $searchTerms ?: $userQuestion;
                    $ollamaCacheKey = 'ollama_embed_' . md5($ollamaText . config('ollama.embedding_model', 'bge-m3'));
                    $ollamaEmbedding = Cache::remember($ollamaCacheKey, 86400, fn() => $this->matsneRetriever->embedQuery($ollamaText));
                    $timings['shared_ollama_embedding'] = $this->elapsedMs($sourceStartedAt);
                    Log::debug('Orchestrator: shared bge-m3 embedding ready');
                } catch (\Throwable $e) {
                    $timings['shared_ollama_embedding'] = $this->elapsedMs($sourceStartedAt ?? microtime(true));
                    Log::warning('Orchestrator: Ollama busy, skipping bge-m3 sources', ['error' => $e->getMessage()]);
                    $ollamaEmbedding = [];
                }
            }

            // ── Matsne semantic search — skipped when article detector found results ─
            if ($needsMatsneSemantic && !empty($ollamaEmbedding)) {
                try {
                    $sourceStartedAt = microtime(true);
                    $matsneResults = $this->matsneRetriever->retrieve(
                        $searchTerms ?: $userQuestion,
                        embedding:    $ollamaEmbedding,
                        domains:      $lawDomains,
                        relevantYear: $relevantYear,
                    );
                    $timings['matsne_retrieval'] = $this->elapsedMs($sourceStartedAt);
                    Log::debug('Orchestrator: matsne retrieval', [
                        'count'   => count($matsneResults),
                        'domains' => $lawDomains,
                    ]);
                } catch (\Throwable $e) {
                    $timings['matsne_retrieval'] = $this->elapsedMs($sourceStartedAt ?? microtime(true));
                    Log::warning('Orchestrator: matsne retrieval failed', ['error' => $e->getMessage()]);
                }
            }

            if ($wantsEu && $triageResult->needsEu && !empty($ollamaEmbedding)) {
                try {
                    $sourceStartedAt = microtime(true);
                    $euResults = $this->euRetriever->retrieve($searchTerms ?: $userQuestion, embedding: $ollamaEmbedding);
                    $timings['eu_retrieval'] = $this->elapsedMs($sourceStartedAt);
                    Log::debug('Orchestrator: EU retrieval', ['count' => count($euResults)]);
                } catch (\Throwable $e) {
                    $timings['eu_retrieval'] = $this->elapsedMs($sourceStartedAt ?? microtime(true));
                    Log::warning('Orchestrator: EU retrieval failed', ['error' => $e->getMessage()]);
                }
            }

            if ($wantsGerman && $triageResult->needsGerman && !empty($ollamaEmbedding)) {
                try {
                    $sourceStartedAt = microtime(true);
                    $germanResults = $this->germanRetriever->retrieve($searchTerms ?: $userQuestion, embedding: $ollamaEmbedding);
                    $timings['german_retrieval'] = $this->elapsedMs($sourceStartedAt);
                    Log::debug('Orchestrator: german retrieval', ['count' => count($germanResults)]);
                } catch (\Throwable $e) {
                    $timings['german_retrieval'] = $this->elapsedMs($sourceStartedAt ?? microtime(true));
                    Log::warning('Orchestrator: german retrieval failed', ['error' => $e->getMessage()]);
                }
            }

            if ($wantsConstCourt && $triageResult->needsConstCourt && !empty($ollamaEmbedding)) {
                try {
                    $sourceStartedAt = microtime(true);
                    $constCourtResults = $this->constCourtRetriever->retrieve($searchTerms ?: $userQuestion, $ollamaEmbedding);
                    $timings['const_court_retrieval'] = $this->elapsedMs($sourceStartedAt);
                    Log::debug('Orchestrator: const_court retrieval', ['count' => count($constCourtResults)]);
                } catch (\Throwable $e) {
                    $timings['const_court_retrieval'] = $this->elapsedMs($sourceStartedAt ?? microtime(true));
                    Log::warning('Orchestrator: const_court retrieval failed', ['error' => $e->getMessage()]);
                }
            }
        }
        $timings['source_retrieval_total'] = $this->elapsedMs($sourcesStartedAt);

        // ── 9. Rule extraction — Stage 1 of two-stage answering ──────────────
        // Extracts concrete rules (deadlines, thresholds) from retrieved matsne articles
        // so the answer generator doesn't default to training priors.
        $extractedRules = [];
        if (!empty($matsneResults) && $this->shouldExtractRules($triageResult, $userQuestion, $matsneResults)) {
            try {
                $stageStartedAt = microtime(true);
                $extractedRules = $this->ruleExtractor->extract($userQuestion, $matsneResults);
                $timings['rule_extraction'] = $this->elapsedMs($stageStartedAt);
            } catch (\Throwable $e) {
                $timings['rule_extraction'] = $this->elapsedMs($stageStartedAt ?? microtime(true));
                Log::warning('Orchestrator: rule extractor failed', ['error' => $e->getMessage()]);
            }
        } elseif (!empty($matsneResults)) {
            $timings['rule_extraction'] = 0;
            $debugFlags['rule_extraction_skipped'] = true;
            $debugFlags['rule_extraction_skip_reason'] = 'fast_exact_article_without_concrete_rule_request';
        }

        // ── 10. Conversation history ──────────────────────────────────────────
        $stageStartedAt = microtime(true);
        $history = $this->buildHistory($chat, $userMessage->id);
        $timings['history_build'] = $this->elapsedMs($stageStartedAt);
        $timings['prepare_total'] = $this->elapsedMs($startTime);

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
            'caseRanking'    => $this->caseRankingSummary($enrichedDecisions),
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
            'timings_ms'         => $timings,
        ];
    }

    /**
     * Saves the assistant message to the DB after the answer text is known.
     */
    public function finalize(Chat $chat, array $ctx, string $answerText, bool $evalWillRun = false): ChatMessage
    {
        $finalizeStartedAt = microtime(true);
        $timings = $ctx['timings_ms'] ?? [];

        $stageStartedAt = microtime(true);
        $postProcessResult = $this->answerPostProcessor->process($answerText, $ctx);
        $answerText = $postProcessResult['text'];
        $timings['answer_postprocess'] = $this->elapsedMs($stageStartedAt);

        $stageStartedAt = microtime(true);
        $citations           = $this->buildCitations($ctx['finalDecisions']);
        $echrCitations       = $this->echrCitationBuilder->build($ctx['echrResults'] ?? []);
        $matsneCitations     = $this->buildMatsneCitations($ctx['matsneResults']     ?? []);
        $euCitations         = $this->buildEuCitations($ctx['euResults']             ?? []);
        $germanCitations     = $this->buildGermanCitations($ctx['germanResults']     ?? []);
        $constCourtCitations = $this->buildConstCourtCitations($ctx['constCourtResults'] ?? []);

        $visibleSourceBudget = $this->visibleSourceBudget($ctx);
        $sourceCounts = [
            'domestic_cases' => count($citations),
            'echr_cases'     => count($echrCitations),
            'matsne_docs'    => count($matsneCitations),
            'eu_docs'        => count($euCitations),
            'german_cases'   => count($germanCitations),
            'const_court'    => count($constCourtCitations),
        ];
        $citationGroups = $this->capCitationGroups([
            'matsne_docs'    => $matsneCitations,
            'domestic_cases' => $citations,
            'echr_cases'     => $echrCitations,
            'const_court'    => $constCourtCitations,
            'eu_docs'        => $euCitations,
            'german_cases'   => $germanCitations,
        ], $visibleSourceBudget);

        $matsneCitations     = $citationGroups['matsne_docs'];
        $citations           = $citationGroups['domestic_cases'];
        $echrCitations       = $citationGroups['echr_cases'];
        $constCourtCitations = $citationGroups['const_court'];
        $euCitations         = $citationGroups['eu_docs'];
        $germanCitations     = $citationGroups['german_cases'];
        $hiddenSourceCounts = [
            'domestic_cases' => max(0, $sourceCounts['domestic_cases'] - count($citations)),
            'echr_cases'     => max(0, $sourceCounts['echr_cases'] - count($echrCitations)),
            'matsne_docs'    => max(0, $sourceCounts['matsne_docs'] - count($matsneCitations)),
            'eu_docs'        => max(0, $sourceCounts['eu_docs'] - count($euCitations)),
            'german_cases'   => max(0, $sourceCounts['german_cases'] - count($germanCitations)),
            'const_court'    => max(0, $sourceCounts['const_court'] - count($constCourtCitations)),
        ];
        $timings['citation_build'] = $this->elapsedMs($stageStartedAt);

        $stageStartedAt = microtime(true);
        $issueTracking  = $this->detectAddressedIssues($ctx['issueList'], $answerText);
        $strategyText   = $ctx['mode'] === 'advocate'
            ? $this->extractStrategySection($answerText)
            : null;
        $timings['issue_tracking'] = $this->elapsedMs($stageStartedAt);

        // ── Citation verification ─────────────────────────────────────────────
        $stageStartedAt = microtime(true);
        $citationCheck = $this->citationVerifier->verify(
            $answerText,
            $ctx['finalDecisions'],
            $ctx['matsneResults'] ?? [],
        );
        $timings['citation_verification'] = $this->elapsedMs($stageStartedAt);

        $stageStartedAt = microtime(true);
        $answerValidation = $this->answerValidator->validate(
            answerText:     $answerText,
            decisions:      $ctx['finalDecisions'],
            matsneResults:  $ctx['matsneResults'] ?? [],
            echrResults:    $ctx['echrResults'] ?? [],
            extractedRules: $ctx['extractedRules'] ?? [],
        );
        $timings['answer_validation'] = $this->elapsedMs($stageStartedAt);

        $domesticConfidence = $ctx['confidence']->label;
        $matsneConfidence   = !empty($ctx['matsneResults']) ? 'high' : 'none';
        $echrConfidence     = !empty($ctx['echrResults'])   ? 'high' : 'none';

        $overallConfidence = $domesticConfidence;
        if ($overallConfidence === 'none' && ($matsneConfidence === 'high' || $echrConfidence === 'high')) {
            $overallConfidence = 'medium';
        }
        $evalEnabled = (bool) config('openai.judge_enabled', false) && ($ctx['mode'] ?? 'explain') !== 'chat';
        $timings['finalize_before_save'] = $this->elapsedMs($finalizeStartedAt);

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
                'source_budget' => [
                    'visible_limit' => $visibleSourceBudget,
                    'total_counts'  => $sourceCounts,
                    'hidden_counts' => $hiddenSourceCounts,
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
                'query_complexity'     => [
                    'score'   => $ctx['triageResult']->complexityScore,
                    'level'   => $ctx['triageResult']->complexityLevel,
                    'reasons' => $ctx['triageResult']->complexityReasons,
                ],
                'sources_active'       => $ctx['sourcePlan']->sourcesActive(),
                'matched_case_ids'     => $ctx['retrieval']->matchedCaseIds,
                'matched_case_numbers' => $ctx['retrieval']->matchedCaseNumbers,
                'relevance_scores'     => $ctx['retrieval']->relevanceScores,
                'case_relevance_ranking' => $ctx['caseRanking'] ?? [],
                'used_chunk_count'     => $ctx['retrieval']->usedChunkCount,
                'used_case_count'      => count($ctx['finalDecisions']),
                'total_meta_found'     => $ctx['retrieval']->totalMetaFound,
                'search_query_used'    => $ctx['parsedQuery']?->terms ?? $ctx['userQuestion'],
                'parsed_filters'       => $ctx['parsedQuery']?->toArray() ?? [],
                'pipeline_ms'          => (int) ((microtime(true) - $ctx['startTime']) * 1000),
                'timings_ms'           => $timings,
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

                // ── Answer validation ─────────────────────────────────────
                'answer_validation' => $answerValidation,
                'answer_validation_verdict' => $answerValidation['verdict'],
                'answer_validation_score' => $answerValidation['score'],
                'answer_postprocess' => [
                    'changed' => !empty($postProcessResult['changes']),
                    'changes' => $postProcessResult['changes'],
                ],

                // ── LLM-as-Judge evaluation (filled later via runEval) ───────
                'eval' => null,
                'eval_enabled' => $evalEnabled,
                'eval_status' => $evalEnabled ? ($evalWillRun ? 'pending' : 'not_requested') : 'disabled',
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
            $this->updateEvalMeta($message, 'disabled');
            return null;
        }

        try {
            $evalResult = $this->evalJudge->evaluate(
                userQuestion:  $ctx['userQuestion'],
                answerText:    $answerText,
                mode:          $ctx['mode'],
                decisions:     $ctx['finalDecisions'],
                matsneResults: $ctx['matsneResults'] ?? [],
                echrResults:   $ctx['echrResults'] ?? [],
                euResults:     $ctx['euResults'] ?? [],
                germanResults: $ctx['germanResults'] ?? [],
                constCourtResults: $ctx['constCourtResults'] ?? [],
            );

            Log::info('EvalJudge: completed', [
                'verdict' => $evalResult['verdict'] ?? null,
                'overall' => $evalResult['overall'] ?? null,
                'mode'    => $ctx['mode'],
            ]);

            $meta         = $message->meta ?? [];
            $meta['eval'] = $evalResult;
            $meta['eval_status'] = 'completed';
            $meta['eval_completed_at'] = now()->toISOString();
            $message->update(['meta' => $meta]);

            return $evalResult;

        } catch (\Throwable $e) {
            Log::warning('EvalJudge: skipped — ' . $e->getMessage());
            $this->updateEvalMeta($message, 'skipped', $e->getMessage());
            return null;
        }
    }

    private function updateEvalMeta(ChatMessage $message, string $status, ?string $error = null): void
    {
        $meta = $message->meta ?? [];
        $meta['eval_status'] = $status;
        $meta['eval_enabled'] = $status !== 'disabled';

        if ($error !== null) {
            $meta['eval_error'] = mb_substr($error, 0, 300);
        }

        $message->update(['meta' => $meta]);
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

    private function shouldUseSemanticNormSupplement(TriageResult $triage, array $matsneResults, string $userQuestion): bool
    {
        if (count($this->extractableRuleDocs($matsneResults)) >= 6) {
            return false;
        }

        if (!$triage->isFastPath()) {
            return true;
        }

        if (!$this->hasExactArticleReference($userQuestion)) {
            return true;
        }

        return empty($this->extractableRuleDocs($matsneResults));
    }

    private function shouldExtractRules(TriageResult $triage, string $userQuestion, array $matsneResults): bool
    {
        $articleDocs = $this->extractableRuleDocs($matsneResults);
        if (empty($articleDocs)) {
            return false;
        }

        if (count($articleDocs) > 8) {
            return false;
        }

        if (!$triage->isFastPath()) {
            return true;
        }

        if (!$this->hasExactArticleReference($userQuestion)) {
            return true;
        }

        if ($this->hasConcreteRuleQuestion($userQuestion)) {
            return true;
        }

        return count($articleDocs) > 2;
    }

    private function hasExactArticleReference(string $question): bool
    {
        return (bool) preg_match(
            '/(?:მუხლ\p{L}*|article|art\.?)\D{0,12}\d{1,4}|\d{1,4}\D{0,12}(?:მუხლ\p{L}*|article|art\.?)/iu',
            $question,
        );
    }

    private function hasConcreteRuleQuestion(string $question): bool
    {
        $lower = mb_strtolower($question);
        $signals = [
            'ვადა',
            'დღე',
            'თვე',
            'წელი',
            'რამდენ',
            'ოდენობ',
            'პროცენტ',
            'თანხ',
            'ზღვარ',
            'მოთხოვნ',
            'წინაპირობ',
            'პროცედურ',
            'შედეგ',
            'სანქც',
            'პასუხისმგებლ',
            'ბათილ',
            'ვალდებულ',
            'deadline',
            'threshold',
            'amount',
            'procedure',
            'consequence',
            'liability',
        ];

        foreach ($signals as $signal) {
            if (str_contains($lower, $signal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractableRuleDocs(array $matsneResults): array
    {
        return array_values(array_filter(
            $matsneResults,
            fn (array $result) => in_array(
                $result['_source'] ?? '',
                ['article_detector', 'semantic_article', 'concept_detector'],
                true,
            ),
        ));
    }

    private function courtRankingQuery(TriageResult $triage, ?string $searchTerms, string $userQuestion): string
    {
        $candidates = [
            $this->extractLabeledLegalIssue($userQuestion),
            trim((string) $searchTerms),
            trim((string) $triage->searchQuery),
        ];

        foreach ($candidates as $candidate) {
            $candidate = $this->normalizeFocusedSearchText((string) $candidate);
            if ($candidate === '') {
                continue;
            }

            if (!$this->isUsableExtractedSearchText($userQuestion, $candidate)) {
                continue;
            }

            if (!$this->isSubstantiveCourtSearchText($candidate)) {
                continue;
            }

            return $candidate;
        }

        return $userQuestion;
    }

    private function shouldUseFocusedCourtQuery(TriageResult $triage, string $focusedQuery, string $userQuestion): bool
    {
        if ($this->normalizedEmbeddingText($focusedQuery) === $this->normalizedEmbeddingText($userQuestion)) {
            return false;
        }

        if (!$this->isUsableExtractedSearchText($userQuestion, $focusedQuery)) {
            return false;
        }

        if (!$this->isSubstantiveCourtSearchText($focusedQuery)) {
            return false;
        }

        $originalLength = mb_strlen(trim($userQuestion));
        $focusedLength = mb_strlen(trim($focusedQuery));

        if ($originalLength <= 500 && $triage->complexityLevel !== 'full') {
            return false;
        }

        return $focusedLength <= max(700, (int) floor($originalLength * 0.85));
    }

    private function extractLabeledLegalIssue(string $question): ?string
    {
        $lines = preg_split('/\R/u', $question) ?: [];
        $captureNext = false;
        $cues = [
            'ძირითადი სამართლებრივი საკითხი',
            'მთავარი სამართლებრივი საკითხი',
            'სამართლებრივი საკითხი ასეთია',
            'საქმის არსი',
            'კითხვა',
        ];

        foreach ($lines as $index => $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if ($captureNext) {
                return $this->appendNearbyCategorySignal($this->stripIssueLineNoise($line), $lines, $index);
            }

            $lower = mb_strtolower($line);
            foreach ($cues as $cue) {
                if (!str_contains($lower, $cue)) {
                    continue;
                }

                $afterColon = preg_split('/[:：]/u', $line, 2);
                if (count($afterColon) === 2 && trim($afterColon[1]) !== '') {
                    return $this->appendNearbyCategorySignal($this->stripIssueLineNoise($afterColon[1]), $lines, $index);
                }

                $captureNext = true;
                break;
            }
        }

        return null;
    }

    /**
     * Preserve an adjacent dispute-category line because it often carries the
     * domain/case-type signal (tax/admin/civil) that a short legal issue omits.
     *
     * @param array<int, string> $lines
     */
    private function appendNearbyCategorySignal(string $issue, array $lines, int $issueLineIndex): string
    {
        for ($i = $issueLineIndex + 1; $i <= min(count($lines) - 1, $issueLineIndex + 3); $i++) {
            $line = trim((string) $lines[$i]);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^დავის\s+(?:სავარაუდო\s+)?(?:მიმართულება\/)?კატეგორია\s*:/u', $line)) {
                return trim($issue . "\n" . $line);
            }

            break;
        }

        return $issue;
    }

    private function stripIssueLineNoise(string $line): string
    {
        $line = preg_replace('/\s*დავის\s+(?:სავარაუდო\s+)?(?:მიმართულება\/)?კატეგორია\s*:.*/u', '', $line) ?? $line;

        return trim($line);
    }

    private function normalizeFocusedSearchText(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\R{2,}/u', "\n", $text) ?? $text);
    }

    private function runPipeline(TriageResult $triage, string $userQuestion, array $sources = []): array
    {
        $timings = [];
        $debugFlags = [
            'hyde_used'          => false,
            'raw_embedding_used' => true,
            'retrieval_strategy' => 'none',
            'domain_classified'  => $triage->caseType,
            'case_type_filter'   => $triage->caseTypeFilter(),
            'query_complexity_level' => $triage->complexityLevel,
            'query_complexity_score' => $triage->complexityScore,
            'query_complexity_reasons' => $triage->complexityReasons,
            'query_normalization' => $triage->queryNormalization,
            'extracted_query_discarded' => false,
        ];

        if ($triage->isChatOnly()) {
            return [RetrievalResult::empty(), null, $debugFlags, null, null, $timings];
        }

        // No court source — skip court retrieval, still need parsedQuery for matsne routing
        if (!$triage->needsCases) {
            $stageStartedAt = microtime(true);
            $parsedQuery = $this->queryParser->parse($userQuestion, $triage->searchQuery);
            $timings['query_parse'] = $this->elapsedMs($stageStartedAt);
            $debugFlags['retrieval_strategy'] = 'norms_only';
            return [RetrievalResult::empty(), $parsedQuery, $debugFlags, $triage->searchQuery, null, $timings];
        }

        $stageStartedAt = microtime(true);
        $parsedQuery = $this->queryParser->parse($userQuestion, $triage->searchQuery);
        $rawSearchText = trim((string) ($triage->searchQuery ?: $parsedQuery->terms));
        if (!$this->isUsableExtractedSearchText($userQuestion, $rawSearchText)) {
            $debugFlags['extracted_query_discarded'] = $rawSearchText !== '';
            $parsedQuery = $this->queryParser->parse($userQuestion, $userQuestion);
        }
        $timings['query_parse'] = $this->elapsedMs($stageStartedAt);

        Log::debug('Orchestrator: parsed query', [
            'terms'     => $parsedQuery->terms,
            'case_type' => $triage->caseTypeFilter(),
            'filters'   => $parsedQuery->toArray(),
        ]);

        // Long casus text often contains procedural boilerplate and task wording.
        // For court retrieval, use the extracted/labeled legal issue as the main
        // semantic signal when it is substantive; keep the full question only for
        // pasted-text/fingerprint fallbacks and final answer generation.
        $courtSearchText = $this->courtRankingQuery($triage, $parsedQuery->terms, $userQuestion);
        $useFocusedCourtQuery = $this->shouldUseFocusedCourtQuery($triage, $courtSearchText, $userQuestion);
        $debugFlags['focused_court_query'] = mb_substr($courtSearchText, 0, 500);
        $debugFlags['focused_court_query_chars'] = mb_strlen($courtSearchText);
        $debugFlags['focused_court_query_used'] = $useFocusedCourtQuery;

        $stageStartedAt = microtime(true);
        $courtEmbeddingText = $useFocusedCourtQuery ? $courtSearchText : $userQuestion;
        $courtEmbeddingKey  = 'ollama_embed_' . md5($courtEmbeddingText . config('ollama.embedding_model', 'bge-m3'));
        $courtEmbedding     = Cache::remember($courtEmbeddingKey, 86400, fn() => $this->ollamaEmbedder->embed($courtEmbeddingText));
        $timings['court_embedding'] = $this->elapsedMs($stageStartedAt);

        $searchEmbedding = null;
        $searchEmbeddingText = $useFocusedCourtQuery
            ? ''
            : trim((string) ($triage->searchQuery ?: $parsedQuery->terms));
        if ($debugFlags['extracted_query_discarded']) {
            $searchEmbeddingText = '';
        }
        if ($searchEmbeddingText !== '' && $this->normalizedEmbeddingText($searchEmbeddingText) !== $this->normalizedEmbeddingText($userQuestion)) {
            $stageStartedAt = microtime(true);
            $searchEmbeddingKey = 'ollama_embed_' . md5($searchEmbeddingText . config('ollama.embedding_model', 'bge-m3'));
            $searchEmbedding = Cache::remember($searchEmbeddingKey, 86400, fn() => $this->ollamaEmbedder->embed($searchEmbeddingText));
            $timings['search_embedding'] = $this->elapsedMs($stageStartedAt);
        }

        $ollamaEmbedding = $searchEmbedding ?: $courtEmbedding;
        $debugFlags['court_embedding_source'] = $useFocusedCourtQuery ? 'focused_query' : 'full_question';
        $debugFlags['full_question_embedding_used'] = !$useFocusedCourtQuery;
        $debugFlags['extracted_query_embedding_used'] = $searchEmbedding !== null;

        // Fingerprint embedding — long queries (>500 chars) may be pasted decision text.
        // Embed the first 300 chars to detect near-duplicate chunks at threshold 0.90.
        $fingerprintEmbedding = null;
        if (mb_strlen($userQuestion) > 500) {
            $stageStartedAt = microtime(true);
            $fpText = mb_substr($userQuestion, 0, 500);
            $fpKey  = 'fp_embed_' . md5($fpText . config('ollama.embedding_model', 'bge-m3'));
            $fingerprintEmbedding = Cache::remember($fpKey, 86400, fn() => $this->ollamaEmbedder->embed($fpText));
            $timings['fingerprint_embedding'] = $this->elapsedMs($stageStartedAt);
        }

        $strategy = $parsedQuery->hasCaseNumber() ? 'case_number+vector' : 'vector+metadata';
        $debugFlags['retrieval_strategy'] = $strategy;

        $stageStartedAt = microtime(true);
        $retrieval = $this->retriever->retrieve(
            rawEmbedding:         $courtEmbedding,
            searchTerms:          $courtSearchText,
            originalQuery:        $userQuestion,
            hydeEmbedding:        $searchEmbedding,
            parsed:               $parsedQuery,
            caseType:             $triage->caseTypeFilter(),
            fingerprintEmbedding: $fingerprintEmbedding,
        );
        $timings['case_retrieval'] = $this->elapsedMs($stageStartedAt);

        return [$retrieval, $parsedQuery, $debugFlags, $parsedQuery->terms, $ollamaEmbedding, $timings];
    }

    private function isUsableExtractedSearchText(string $original, string $searchText): bool
    {
        $searchText = trim($searchText);
        if ($searchText === '') {
            return false;
        }

        if ($this->normalizedEmbeddingText($searchText) === $this->normalizedEmbeddingText($original)) {
            return true;
        }

        $originalTokens = $this->searchGuardTokens($original);
        $searchTokens = $this->searchGuardTokens($searchText);

        if (empty($searchTokens)) {
            return false;
        }

        return count(array_intersect($originalTokens, $searchTokens)) > 0;
    }

    private function isSubstantiveCourtSearchText(string $searchText): bool
    {
        if ($this->hasExactArticleReference($searchText)) {
            return true;
        }

        return count($this->searchGuardTokens($searchText)) >= 2;
    }

    /**
     * @return array<int, string>
     */
    private function searchGuardTokens(string $text): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];
        $stopWords = [
            'და', 'ან', 'რომ', 'არის', 'იყო', 'თუ', 'რა', 'რას', 'როგორ', 'საკითხი',
            'სასამართლო', 'სასამართლოს', 'საქმე', 'საქმეში', 'გადაწყვეტილება', 'გადაწყვეტილების',
            'სარჩელი', 'საჩივარი', 'კაზუსი', 'კლიენტი', 'მხარე', 'მხარეებს', 'მთავარი',
            'სამართლებრივი', 'დავალება', 'წყარო', 'წყაროები', 'რელევანტური',
            'პრაქტიკა', 'უზენაესი', 'მოძებნე', 'გამოყავი', 'დასკვნა', 'პასუხი',
        ];

        $tokens = array_filter($tokens, fn (string $token) => mb_strlen($token) >= 4 && !in_array($token, $stopWords, true));

        return array_values(array_unique(array_map(
            fn (string $token) => mb_substr($token, 0, min(7, mb_strlen($token))),
            $tokens,
        )));
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

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function visibleSourceBudget(array $ctx): int
    {
        $mode = (string) ($ctx['mode'] ?? 'explain');
        $level = (string) ($ctx['triageResult']->complexityLevel ?? 'normal');
        $isComplex = in_array($mode, ['advocate', 'compare', 'summarize', 'find'], true)
            || in_array($level, ['complex', 'full'], true);

        $configKey = $isComplex ? 'openai.max_visible_sources_complex' : 'openai.max_visible_sources_default';

        return max(1, (int) config($configKey, $isComplex ? 8 : 5));
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $groups
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function capCitationGroups(array $groups, int $budget): array
    {
        $order = ['matsne_docs', 'domestic_cases', 'echr_cases', 'const_court', 'eu_docs', 'german_cases'];
        $perGroupMax = [
            'matsne_docs'    => 3,
            'domestic_cases' => 3,
            'echr_cases'     => 2,
            'const_court'    => 2,
            'eu_docs'        => 1,
            'german_cases'   => 1,
        ];

        $remaining = max(1, $budget);
        $capped = array_fill_keys($order, []);

        foreach ($order as $key) {
            $items = array_values($groups[$key] ?? []);
            $take = min(count($items), $perGroupMax[$key], $remaining);
            $capped[$key] = array_slice($items, 0, $take);
            $remaining -= $take;

            if ($remaining <= 0) {
                break;
            }
        }

        return $capped;
    }

    private function normalizedEmbeddingText(string $text): string
    {
        $text = mb_strtolower(trim($text));

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function buildMatsneCitations(array $matsneResults): array
    {
        return array_map(fn(array $r) => [
            'type'               => 'matsne',
            'matsne_id'          => $r['matsne_id'],
            'title'              => $r['title'],
            'article_num'        => $r['_article_num'] ?? null,
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
            $caseType = $d['case_type'] ?? 'administrative';

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
                'semantic_relevance_score' => $d['semantic_relevance_score'] ?? null,
                'semantic_relevance_confidence' => $d['semantic_relevance']['confidence'] ?? null,
                'ranking_explanation' => $d['ranking_explanation'] ?? null,
                'answer_role'     => $d['answer_role'] ?? null,
                'answer_role_label' => $d['answer_role_label'] ?? null,
                'answer_rank'     => $d['answer_rank'] ?? null,
                'case_type'       => $caseType,
                'url'             => "/fullcase/{$caseType}/{$d['case_id']}",
            ];
        }, $decisions);
    }

    /**
     * Marks top decisions as main authorities and the rest as similar support.
     *
     * @param array<int, array<string, mixed>> $decisions
     * @return array<int, array<string, mixed>>
     */
    private function annotateDecisionRoles(array $decisions): array
    {
        $primaryLimit = max(1, (int) config('openai.primary_case_limit', 2));

        return array_values(array_map(function (array $decision, int $index) use ($primaryLimit) {
            $isStrongAuthority = $this->isStrongCourtAuthority($decision, $index, $primaryLimit);
            $isWeakMatch = $this->hasSemanticRelevanceProfile($decision) && !$isStrongAuthority;
            $role = $isStrongAuthority ? 'primary' : 'supporting';

            $decision['answer_rank'] = $index + 1;
            $decision['answer_role'] = $role;
            $decision['answer_role_label'] = $role === 'primary'
                ? 'მთავარი შესაბამისი საქმე'
                : 'დამხმარე მსგავსი საქმე';
            $decision['usage_instruction'] = match (true) {
                $role === 'primary' => 'Use as a main authority for the legal answer.',
                $isWeakMatch => 'Weak/analogous match only. Do NOT cite as direct authority; mention only if clearly useful as limited analogy.',
                default => 'Use only as supporting analogous practice if it confirms the same legal issue.',
            };

            if ($isWeakMatch) {
                $decision['quality_flags'] = array_values(array_unique(array_merge(
                    $decision['quality_flags'] ?? [],
                    ['weak_context_match'],
                )));
            }

            return $decision;
        }, $decisions, array_keys($decisions)));
    }

    private function isStrongCourtAuthority(array $decision, int $index, int $primaryLimit): bool
    {
        $semantic = $decision['semantic_relevance'] ?? [];

        if (($semantic['case_card_legal_issue_exact'] ?? false) === true) {
            return true;
        }

        $sources = $decision['match_sources'] ?? [];
        $relevance = (float) ($decision['relevance_score'] ?? 0.0);
        if (in_array('case_number', $sources, true)
            || in_array('fingerprint', $sources, true)
            || (in_array('pasted_text', $sources, true) && $relevance >= 0.95)
        ) {
            return true;
        }

        if (!$this->hasSemanticRelevanceProfile($decision)) {
            return $index < $primaryLimit;
        }

        if ($index >= $primaryLimit) {
            return false;
        }

        $score = (float) ($decision['semantic_relevance_score'] ?? 0.0);
        $confidence = $semantic['confidence'] ?? 'low';

        return in_array($confidence, ['high', 'medium'], true) && $score >= 45.0;
    }

    private function hasSemanticRelevanceProfile(array $decision): bool
    {
        return isset($decision['semantic_relevance_score'])
            || isset($decision['semantic_relevance']['confidence']);
    }

    /**
     * @param array<int, array<string, mixed>> $decisions
     * @return array<int, array<string, mixed>>
     */
    private function caseRankingSummary(array $decisions): array
    {
        return array_map(fn (array $d) => [
            'case_id' => $d['case_id'] ?? null,
            'case_num' => $d['case_num'] ?? null,
            'answer_role' => $d['answer_role'] ?? null,
            'answer_rank' => $d['answer_rank'] ?? null,
            'semantic_relevance_score' => $d['semantic_relevance_score'] ?? null,
            'semantic_relevance' => $d['semantic_relevance'] ?? null,
            'ranking_explanation' => $d['ranking_explanation'] ?? null,
            'match_sources' => $d['match_sources'] ?? [],
            'retrieval_relevance_score' => $d['relevance_score'] ?? null,
            'combined_score' => $d['combined_score'] ?? null,
        ], array_slice($decisions, 0, 12));
    }
}
