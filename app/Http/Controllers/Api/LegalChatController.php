<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreChatMessageRequest;
use App\Http\Requests\Api\StoreChatRequest;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Services\Legal\LegalChatOrchestratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class LegalChatController extends Controller
{
    public function __construct(
        private readonly LegalChatOrchestratorService $orchestrator,
    ) {}

    // -------------------------------------------------------------------------
    // GET /api/chats
    // -------------------------------------------------------------------------
    public function index(): JsonResponse
    {
        $chats = Chat::orderByDesc('updated_at')
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
            'title' => $request->input('title'),
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
    // POST /api/chats/{chat}/messages
    // -------------------------------------------------------------------------
    public function sendMessage(StoreChatMessageRequest $request, Chat $chat): JsonResponse
    {
        try {
            $result = $this->orchestrator->handle(
                chat:          $chat,
                userQuestion:  $request->input('message'),
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
    // Helpers
    // -------------------------------------------------------------------------
    private function formatMessage(ChatMessage $message): array
    {
        return [
            'id'         => $message->id,
            'chat_id'    => $message->chat_id,
            'role'       => $message->role,
            'content'    => $message->content,
            'citations'  => $message->meta['citations'] ?? [],
            'meta'       => [
                'retrieval_mode'    => $message->meta['retrieval_mode'] ?? null,
                'used_case_count'   => $message->meta['used_case_count'] ?? 0,
                'used_chunk_count'  => $message->meta['used_chunk_count'] ?? 0,
            ],
            'created_at' => $message->created_at?->toISOString(),
        ];
    }
}
