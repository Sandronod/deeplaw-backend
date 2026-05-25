<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreChatMessageRequest;
use App\Http\Requests\Api\StoreChatRequest;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Contracts\AnswerServiceInterface;
use App\Services\Legal\LegalChatOrchestratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class LegalChatController extends Controller
{
    public function __construct(
        private readonly LegalChatOrchestratorService $orchestrator,
        private readonly AnswerServiceInterface       $answerer,
    ) {}

    // -------------------------------------------------------------------------
    // GET /api/chats
    // -------------------------------------------------------------------------
    public function index(): JsonResponse
    {
        $chats = Chat::where('user_id', auth()->id())
            ->orderByDesc('updated_at')
            ->select(['id', 'title', 'created_at', 'updated_at'])
            ->get();

        return response()->json(['data' => $chats]);
    }

    // -------------------------------------------------------------------------
    // POST /api/chats
    // -------------------------------------------------------------------------
    public function store(StoreChatRequest $request): JsonResponse
    {
        $chat = Chat::create([
            'user_id' => auth()->id(),
            'title'   => $request->input('title'),
        ]);

        return response()->json(['data' => $chat], 201);
    }

    // -------------------------------------------------------------------------
    // GET /api/chats/{chat}
    // -------------------------------------------------------------------------
    public function show(Chat $chat): JsonResponse
    {
        return response()->json(['data' => $chat]);
    }

    // -------------------------------------------------------------------------
    // PATCH /api/chats/{chat}/title
    // -------------------------------------------------------------------------
    public function updateTitle(Request $request, Chat $chat): JsonResponse
    {
        $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $chat->update(['title' => $request->input('title')]);

        return response()->json(['data' => $chat]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/chats/{chat}
    // -------------------------------------------------------------------------
    public function destroy(Chat $chat): JsonResponse
    {
        $chat->delete();

        return response()->json(['message' => 'Chat deleted.']);
    }

    // -------------------------------------------------------------------------
    // GET /api/chats/{chat}/messages
    // -------------------------------------------------------------------------
    public function messages(Chat $chat): JsonResponse
    {
        $messages = $chat->messages()
            ->select(['id', 'chat_id', 'role', 'content', 'meta', 'created_at'])
            ->get()
            ->map(fn ($m) => $this->formatMessage($m));

        return response()->json(['data' => $messages]);
    }

    // -------------------------------------------------------------------------
    // POST /api/chats/{chat}/messages  (non-streaming fallback)
    // -------------------------------------------------------------------------
    public function sendMessage(StoreChatMessageRequest $request, Chat $chat): JsonResponse
    {
        set_time_limit(180);
        try {
            $result = $this->orchestrator->handle(
                chat:         $chat,
                userQuestion: $request->input('message'),
                sources:      $request->input('sources', ['court', 'matsne', 'eu', 'german', 'const_court']),
            );

            return response()->json([
                'data' => $this->formatMessage($result['message']),
            ]);

        } catch (RuntimeException $e) {
            Log::error('LegalChat orchestrator error', [
                'chat_id' => $chat->id,
                'error'   => $e->getMessage(),
            ]);

            return response()->json([
                'error'   => 'AI service error. Please try again.',
                'details' => app()->isLocal() ? $e->getMessage() : null,
            ], 503);

        } catch (Throwable $e) {
            Log::error('LegalChat unexpected error', [
                'chat_id' => $chat->id,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'error' => 'An unexpected error occurred.',
            ], 500);
        }
    }

    // -------------------------------------------------------------------------
    // POST /api/chats/{chat}/messages/stream  (SSE streaming)
    // -------------------------------------------------------------------------
    public function streamMessage(StoreChatMessageRequest $request, Chat $chat): StreamedResponse
    {
        return response()->stream(function () use ($request, $chat) {

            set_time_limit(120);

            // Disable output buffering for true streaming
            if (ob_get_level()) {
                ob_end_clean();
            }

            $emit = function (string $event, array $data): void {
                echo "event: {$event}\n";
                echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
                if (ob_get_level()) ob_flush();
                flush();
            };

            try {
                // ── Stage 1: retrieval pipeline ───────────────────────────────
                $emit('status', ['phase' => 'searching']);

                $ctx = $this->orchestrator->prepare(
                    chat:         $chat,
                    userQuestion: $request->input('message'),
                    sources:      $request->input('sources', ['court', 'matsne', 'eu', 'german', 'const_court']),
                );

                // ── Stage 2: stream LLM tokens ────────────────────────────────
                $emit('status', ['phase' => 'writing']);

                $fullText  = '';
                $generator = $this->answerer->streamTokens(
                    userQuestion:    $ctx['userQuestion'],
                    decisions:       $ctx['finalDecisions'],
                    historyMessages: $ctx['history'],
                    totalFound:      $ctx['retrieval']->totalMetaFound,
                    mode:            $ctx['mode'],
                    confidence:      $ctx['confidence'],
                    lawResults:      $ctx['lawResults']   ?? [],
                    echrResults:     $ctx['echrResults']  ?? [],
                    matsneResults:   $ctx['matsneResults'] ?? [],
                    euResults:          $ctx['euResults']          ?? [],
                    germanResults:      $ctx['germanResults']      ?? [],
                    constCourtResults:  $ctx['constCourtResults']  ?? [],
                    sources:            $ctx['sources'],
                    issueList:          $ctx['issueList'],
                );

                foreach ($generator as $token) {
                    $fullText .= $token;
                    $emit('token', ['token' => $token]);
                }

                // ── Stage 3: persist & emit final payload ─────────────────────
                $assistantMessage = $this->orchestrator->finalize($chat, $ctx, $fullText);

                $emit('done', [
                    'message_id'      => $assistantMessage->id,
                    'citations'        => $assistantMessage->meta['citations']          ?? [],
                    'law_citations'    => $assistantMessage->meta['law_citations']      ?? [],
                    'matsne_citations' => $assistantMessage->meta['matsne_citations']   ?? [],
                    'eu_citations'     => $assistantMessage->meta['eu_citations']       ?? [],
                    'german_citations'      => $assistantMessage->meta['german_citations']       ?? [],
                    'const_court_citations' => $assistantMessage->meta['const_court_citations']  ?? [],
                    'meta'         => [
                        'retrieval_mode'   => $assistantMessage->meta['retrieval_mode']   ?? null,
                        'answer_mode'      => $assistantMessage->meta['answer_mode']      ?? null,
                        'confidence'       => $assistantMessage->meta['confidence']       ?? null,
                        'confidence_note'  => $assistantMessage->meta['confidence_note']  ?? null,
                        'used_case_count'  => $assistantMessage->meta['used_case_count']  ?? 0,
                        'used_chunk_count' => $assistantMessage->meta['used_chunk_count'] ?? 0,
                        'pipeline_ms'      => $assistantMessage->meta['pipeline_ms']      ?? null,
                    ],
                ]);

            } catch (Throwable $e) {
                Log::error('LegalChat stream error', [
                    'chat_id' => $chat->id,
                    'error'   => $e->getMessage(),
                ]);

                $emit('error', ['message' => 'შეტყობინების გენერირება ვერ მოხერხდა.']);
            }

        }, 200, [
            'Content-Type'      => 'text/event-stream',
            'Cache-Control'     => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
            'Connection'        => 'keep-alive',
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------
    private function formatMessage(ChatMessage $message): array
    {
        return [
            'id'         => $message->id,
            'chat_id'    => $message->chat_id,
            'role'       => $message->role,
            'content'    => $message->content,
            'citations'        => $message->meta['citations']          ?? [],
            'law_citations'    => $message->meta['law_citations']      ?? [],
            'matsne_citations' => $message->meta['matsne_citations']   ?? [],
            'eu_citations'     => $message->meta['eu_citations']       ?? [],
            'german_citations'      => $message->meta['german_citations']       ?? [],
            'const_court_citations' => $message->meta['const_court_citations']  ?? [],
            'meta'          => [
                'retrieval_mode'   => $message->meta['retrieval_mode']   ?? null,
                'answer_mode'      => $message->meta['answer_mode']      ?? null,
                'confidence'       => $message->meta['confidence']       ?? null,
                'confidence_note'  => $message->meta['confidence_note']  ?? null,
                'used_case_count'  => $message->meta['used_case_count']  ?? 0,
                'used_chunk_count' => $message->meta['used_chunk_count'] ?? 0,
                'pipeline_ms'      => $message->meta['pipeline_ms']      ?? null,
            ],
            'created_at' => $message->created_at?->toISOString(),
        ];
    }
}
