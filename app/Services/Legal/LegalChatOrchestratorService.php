<?php

namespace App\Services\Legal;

use App\Models\Chat;
use App\Models\ChatMessage;
use App\Services\AI\IntentClassifierService;
use App\Services\AI\OpenAIEmbeddingService;
use App\Services\AI\OpenAILegalAnswerService;
use App\Services\AI\QueryExtractorService;
use App\Services\Chat\ChatTitleService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LegalChatOrchestratorService
{
    public function __construct(
        private readonly OpenAIEmbeddingService    $embedder,
        private readonly LegalCaseRetrieverService $retriever,
        private readonly OpenAILegalAnswerService  $answerer,
        private readonly ChatTitleService          $titleService,
        private readonly QueryExtractorService     $queryExtractor,
        private readonly IntentClassifierService   $intentClassifier,
    ) {}

    /**
     * Full pipeline: save user message → retrieve → answer → save assistant message.
     *
     * @return array{message: ChatMessage, retrieval: array}
     */
    public function handle(Chat $chat, string $userQuestion): array
    {
        return DB::transaction(function () use ($chat, $userQuestion) {

            // 1. Save user message
            $userMessage = ChatMessage::create([
                'chat_id' => $chat->id,
                'role'    => 'user',
                'content' => $userQuestion,
            ]);

            // 2. Auto-set chat title on first message
            if (is_null($chat->title)) {
                $chat->update(['title' => $this->titleService->generateFromMessage($userQuestion)]);
            }

            // 3. Intent classification — retrieval გავუშვათ მხოლოდ სამართლებრივ მოთხოვნებზე
            $intent = $this->intentClassifier->classify($userQuestion);

            if ($intent === 'chat') {
                // მისალმება / ზოგადი საუბარი — retrieval გამოვტოვოთ
                $retrieval   = $this->retriever->emptyRetrieval();
                $searchQuery = $userQuestion;
            } else {
                // 4a. Query extraction — task instruction სიტყვები ამოვიღოთ embedding-მდე
                $searchQuery = $this->queryExtractor->extract($userQuestion);
                $embedding   = $this->embedder->embed($searchQuery);

                // 4b. Retrieve: vector search + metadata search (case_num, court…)
                $retrieval = $this->retriever->retrieve($embedding, $searchQuery);
            }

            // 5. Build conversation history (last N messages, excluding the one we just saved)
            $historyLimit = config('openai.context_history_messages', 6);
            $history = ChatMessage::where('chat_id', $chat->id)
                ->where('id', '<', $userMessage->id)
                ->whereIn('role', ['user', 'assistant'])
                ->orderByDesc('created_at')
                ->limit($historyLimit)
                ->get()
                ->reverse()
                ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
                ->values()
                ->toArray();

            // 6. Get answer from OpenAI
            $answerText = $this->answerer->answer(
                userQuestion:    $userQuestion,
                decisions:       $retrieval['decisions'],
                historyMessages: $history,
                totalFound:      $retrieval['total_meta_found'] ?? 0,
            );

            // 7. Build citations for Angular UI
            $citations = $this->buildCitations($retrieval['decisions']);

            // 8. Save assistant message with full retrieval metadata
            $assistantMessage = ChatMessage::create([
                'chat_id' => $chat->id,
                'role'    => 'assistant',
                'content' => $answerText,
                'meta'    => [
                    'retrieval_mode'       => $intent === 'chat' ? 'chat' : (empty($retrieval['decisions']) ? 'no_results' : 'grounded'),
                    'matched_case_ids'     => $retrieval['matched_case_ids'],
                    'matched_case_numbers' => $retrieval['matched_case_numbers'],
                    'relevance_scores'     => $retrieval['relevance_scores'],
                    'citations'            => $citations,
                    'used_chunk_count'     => $retrieval['used_chunk_count'],
                    'used_case_count'      => $retrieval['used_case_count'],
                    'total_meta_found'     => $retrieval['total_meta_found'] ?? 0,
                    'search_query_used'    => $searchQuery,
                ],
            ]);

            return [
                'message'   => $assistantMessage,
                'retrieval' => $retrieval,
            ];
        });
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
