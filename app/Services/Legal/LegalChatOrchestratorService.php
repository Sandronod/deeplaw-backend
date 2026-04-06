<?php

namespace App\Services\Legal;

use App\DTOs\ConfidenceResult;
use App\DTOs\ParsedQuery;
use App\DTOs\RetrievalResult;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Services\AI\ConfidenceAssessor;
use App\Services\AI\EmbedCacheService;
use App\Services\AI\EvidenceBuilderService;
use App\Services\AI\HyDEService;
use App\Services\AI\IntentClassifierService;
use App\Contracts\AnswerServiceInterface;
use App\Services\AI\QueryExtractorService;
use App\Services\AI\QueryParserService;
use App\Services\AI\RerankerService;
use App\Services\Chat\ChatTitleService;
use App\Services\Echr\EchrCitationBuilder;
use App\Services\Echr\EchrRetrieverService;
use App\Services\Legal\KnowledgeSourceRouter;
use App\Services\Legal\LawRetrieverService;
use Illuminate\Support\Facades\Log;

class LegalChatOrchestratorService
{
    public function __construct(
        private readonly EmbedCacheService         $embedCache,
        private readonly LegalCaseRetrieverService $retriever,
        private readonly AnswerServiceInterface    $answerer,
        private readonly ChatTitleService          $titleService,
        private readonly QueryExtractorService     $queryExtractor,
        private readonly QueryParserService        $queryParser,
        private readonly IntentClassifierService   $intentClassifier,
        private readonly HyDEService               $hyde,
        private readonly ConfidenceAssessor        $confidenceAssessor,
        private readonly RerankerService           $reranker,
        private readonly EvidenceBuilderService    $evidenceBuilder,
        private readonly KnowledgeSourceRouter     $sourceRouter,
        private readonly LawRetrieverService       $lawRetriever,
        private readonly EchrRetrieverService      $echrRetriever,
        private readonly EchrCitationBuilder       $echrCitationBuilder,
    ) {}

    /**
     * Full pipeline (non-streaming): prepare → answer → finalize.
     */
    public function handle(Chat $chat, string $userQuestion): array
    {
        $ctx = $this->prepare($chat, $userQuestion);

        $answerText = $this->answerer->answer(
            userQuestion:    $ctx['userQuestion'],
            decisions:       $ctx['finalDecisions'],
            historyMessages: $ctx['history'],
            totalFound:      $ctx['retrieval']->totalMetaFound,
            mode:            $ctx['mode'],
            confidence:      $ctx['confidence'],
            lawResults:      $ctx['lawResults'],
            echrResults:     $ctx['echrResults'],
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
    public function prepare(Chat $chat, string $userQuestion): array
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

        // ── 3. Intent + mode classification ──────────────────────────────────
        $intent = $this->intentClassifier->classify($userQuestion);
        $mode   = $intent === 'chat'
            ? 'chat'
            : $this->intentClassifier->classifyMode($userQuestion);

        Log::debug('Orchestrator: intent', [
            'intent'       => $intent,
            'mode'         => $mode,
            'question_len' => mb_strlen($userQuestion),
        ]);

        // ── 4. Retrieval pipeline ─────────────────────────────────────────────
        [$retrieval, $parsedQuery, $debugFlags, $rawEmbedding, $searchTerms] = $this->runPipeline($intent, $userQuestion);

        // ── 5. Confidence assessment ──────────────────────────────────────────
        $confidence = $this->confidenceAssessor->assess($retrieval);

        Log::debug('Orchestrator: confidence', [
            'label'       => $confidence->label,
            'score'       => $confidence->score,
            'explanation' => $confidence->explanation,
        ]);

        // ── 6. Rerank candidates → top K ─────────────────────────────────────
        $topK           = (int) config('openai.retrieval_case_limit', 3);
        $initialCount   = count($retrieval->decisions);
        $finalDecisions = $this->reranker->rerank($userQuestion, $retrieval->decisions, $topK);

        $debugFlags['reranked']                 = count($finalDecisions) !== $initialCount && $initialCount > $topK;
        $debugFlags['initial_candidates_count'] = $initialCount;
        $debugFlags['final_candidates_count']   = count($finalDecisions);

        // ── 7. Evidence annotations ───────────────────────────────────────────
        $enrichedDecisions = $this->evidenceBuilder->build($userQuestion, $finalDecisions);

        // ── 8. Source routing + Law + ECHR retrieval ──────────────────────────
        $sourcePlan = $this->sourceRouter->plan($parsedQuery ?? $userQuestion);
        $lawResults  = [];
        $echrResults = [];

        if ($intent !== 'chat' && isset($rawEmbedding)) {
            if ($sourcePlan->useLaw) {
                // Pass full $userQuestion — law retriever needs the law name for title matching.
                $lawResults = $this->lawRetriever->retrieve($rawEmbedding, $userQuestion);

                Log::debug('Orchestrator: law retrieval', [
                    'law_count' => count($lawResults),
                ]);
            }

            if ($sourcePlan->useEchr && $parsedQuery) {
                $echrResults = $this->echrRetriever->retrieve($rawEmbedding, $userQuestion, $parsedQuery);

                Log::debug('Orchestrator: echr retrieval', [
                    'echr_count' => count($echrResults),
                ]);
            }
        }

        // ── 9. Conversation history ───────────────────────────────────────────
        $history = $this->buildHistory($chat, $userMessage->id);

        return [
            'startTime'      => $startTime,
            'userMessage'    => $userMessage,
            'userQuestion'   => $userQuestion,
            'intent'         => $intent,
            'mode'           => $mode,
            'retrieval'      => $retrieval,
            'parsedQuery'    => $parsedQuery,
            'debugFlags'     => $debugFlags,
            'confidence'     => $confidence,
            'finalDecisions' => $enrichedDecisions,
            'lawResults'     => $lawResults,
            'echrResults'    => $echrResults,
            'sourcePlan'     => $sourcePlan,
            'history'        => $history,
        ];
    }

    /**
     * Saves the assistant message to the DB after the answer text is known.
     */
    public function finalize(Chat $chat, array $ctx, string $answerText): ChatMessage
    {
        $citations     = $this->buildCitations($ctx['finalDecisions']);
        $lawCitations  = $this->buildLawCitations($ctx['lawResults']  ?? []);
        $echrCitations = $this->echrCitationBuilder->build($ctx['echrResults'] ?? []);

        $domesticConfidence = $ctx['confidence']->label;
        $lawConfidence      = !empty($ctx['lawResults'])  ? 'high' : 'none';
        $echrConfidence     = !empty($ctx['echrResults']) ? 'high' : 'none';

        // Overall = domestic confidence (primary source).
        // Boost to 'medium' if law/echr results exist but domestic is empty.
        $overallConfidence = $domesticConfidence;
        if ($overallConfidence === 'none' && ($lawConfidence === 'high' || $echrConfidence === 'high')) {
            $overallConfidence = 'medium';
        }

        return ChatMessage::create([
            'chat_id' => $chat->id,
            'role'    => 'assistant',
            'content' => $answerText,
            'meta'    => [
                // ── Evidence (structured) ─────────────────────────────────────
                'evidence' => [
                    'laws'           => $lawCitations,
                    'domestic_cases' => $citations,
                    'echr_cases'     => $echrCitations,
                ],

                // ── Confidence breakdown ──────────────────────────────────────
                'law_confidence'      => $lawConfidence,
                'domestic_confidence' => $domesticConfidence,
                'echr_confidence'     => $echrConfidence,
                'overall_confidence'  => $overallConfidence,

                // ── Backward-compatible flat fields ───────────────────────────
                'citations'            => $citations,
                'law_citations'        => $lawCitations,
                'echr_citations'       => $echrCitations,
                'confidence'           => $overallConfidence,
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
            ],
        ]);
    }

    // ── Pipeline ──────────────────────────────────────────────────────────────

    private function runPipeline(string $intent, string $userQuestion): array
    {
        $debugFlags = [
            'hyde_used'          => false,
            'raw_embedding_used' => true,
            'retrieval_strategy' => 'none',
        ];

        if ($intent === 'chat') {
            return [RetrievalResult::empty(), null, $debugFlags, null, null];
        }

        $searchTerms = $this->queryExtractor->extract($userQuestion);
        $parsedQuery = $this->queryParser->parse($userQuestion, $searchTerms);

        Log::debug('Orchestrator: parsed query', [
            'terms'   => $parsedQuery->terms,
            'filters' => $parsedQuery->toArray(),
        ]);

        $hasCaseNumber = $parsedQuery->hasCaseNumber();

        if ($hasCaseNumber) {
            Log::debug('Orchestrator: case number mode, skipping HyDE');
            $rawEmbedding = $this->embedCache->embed($searchTerms);
            $debugFlags['retrieval_strategy'] = 'case_number+vector';

            $retrieval = $this->retriever->retrieve(
                rawEmbedding:  $rawEmbedding,
                searchTerms:   $parsedQuery->terms,
                originalQuery: $userQuestion,
                hydeEmbedding: null,
                parsed:        $parsedQuery,
            );
        } else {
            $hydeDoc = $this->hyde->generate($searchTerms);
            [$rawEmbedding, $hydeEmbedding] = $this->embedCache->embedBatch([
                $searchTerms,
                $hydeDoc,
            ]);
            $debugFlags['hyde_used']          = true;
            $debugFlags['retrieval_strategy'] = 'dual_vector+metadata';
            Log::debug('Orchestrator: dual embedding retrieved');

            $retrieval = $this->retriever->retrieve(
                rawEmbedding:  $rawEmbedding,
                searchTerms:   $parsedQuery->terms,
                originalQuery: $userQuestion,
                hydeEmbedding: $hydeEmbedding,
                parsed:        $parsedQuery,
            );
        }

        return [$retrieval, $parsedQuery, $debugFlags, $rawEmbedding, $searchTerms];
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

    private function buildLawCitations(array $lawResults): array
    {
        return array_map(fn(\App\DTOs\LawResult $r) => [
            'type'         => 'law',
            'law_id'       => $r->lawId,
            'article_id'   => $r->articleId,
            'title'        => $r->title,
            'article_num'  => $r->articleNum,
            'article_title'=> $r->articleTitle,
            'excerpt'      => $r->excerpt,
            'similarity'   => $r->similarity,
            'url'          => $r->sourceUrl,
        ], $lawResults);
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
                'url'             => "https://www.supremecourt.ge/ka/fullcase/{$d['case_id']}/0",
            ];
        }, $decisions);
    }
}
