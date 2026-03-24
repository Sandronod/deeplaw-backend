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
use App\Services\AI\OpenAILegalAnswerService;
use App\Services\AI\QueryExtractorService;
use App\Services\AI\QueryParserService;
use App\Services\AI\RerankerService;
use App\Services\Chat\ChatTitleService;
use Illuminate\Support\Facades\Log;

class LegalChatOrchestratorService
{
    public function __construct(
        private readonly EmbedCacheService         $embedCache,
        private readonly LegalCaseRetrieverService $retriever,
        private readonly OpenAILegalAnswerService  $answerer,
        private readonly ChatTitleService          $titleService,
        private readonly QueryExtractorService     $queryExtractor,
        private readonly QueryParserService        $queryParser,
        private readonly IntentClassifierService   $intentClassifier,
        private readonly HyDEService               $hyde,
        private readonly ConfidenceAssessor        $confidenceAssessor,
        private readonly RerankerService           $reranker,
        private readonly EvidenceBuilderService    $evidenceBuilder,
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
        [$retrieval, $parsedQuery, $debugFlags] = $this->runPipeline($intent, $userQuestion);

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

        // ── 8. Conversation history ───────────────────────────────────────────
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
            'history'        => $history,
        ];
    }

    /**
     * Saves the assistant message to the DB after the answer text is known.
     */
    public function finalize(Chat $chat, array $ctx, string $answerText): ChatMessage
    {
        $citations = $this->buildCitations($ctx['finalDecisions']);

        return ChatMessage::create([
            'chat_id' => $chat->id,
            'role'    => 'assistant',
            'content' => $answerText,
            'meta'    => [
                'retrieval_mode'       => $this->resolveMode($ctx['intent'], $ctx['retrieval']),
                'answer_mode'          => $ctx['mode'],
                'confidence'           => $ctx['confidence']->label,
                'confidence_score'     => $ctx['confidence']->score,
                'confidence_note'      => $ctx['confidence']->explanation,
                'matched_case_ids'     => $ctx['retrieval']->matchedCaseIds,
                'matched_case_numbers' => $ctx['retrieval']->matchedCaseNumbers,
                'relevance_scores'     => $ctx['retrieval']->relevanceScores,
                'citations'            => $citations,
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
            return [RetrievalResult::empty(), null, $debugFlags];
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

        return [$retrieval, $parsedQuery, $debugFlags];
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
