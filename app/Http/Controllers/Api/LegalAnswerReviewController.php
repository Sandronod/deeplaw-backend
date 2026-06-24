<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreLegalAnswerReviewRequest;
use App\Models\ChatMessage;
use App\Models\LegalAnswerReview;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegalAnswerReviewController extends Controller
{
    public function show(Request $request, ChatMessage $message): JsonResponse
    {
        $this->authorizeMessageAccess($request->user(), $message);

        $review = $message->latestHumanReview()->first();

        return response()->json([
            'data' => $review ? $this->formatReview($review) : null,
        ]);
    }

    public function store(StoreLegalAnswerReviewRequest $request, ChatMessage $message): JsonResponse
    {
        $this->authorizeMessageAccess($request->user(), $message);

        if ($message->role !== 'assistant') {
            return response()->json([
                'message' => 'Only assistant answers can be reviewed.',
            ], 422);
        }

        $review = LegalAnswerReview::updateOrCreate(
            [
                'chat_message_id' => $message->id,
                'reviewer_id' => $request->user()->id,
            ],
            [
                'chat_id' => $message->chat_id,
                'reviewer_name' => $request->input('reviewer_name') ?: $request->user()->full_name,
                'overall_score' => $request->integer('overall_score'),
                'legal_accuracy_score' => $this->nullableScore($request, 'legal_accuracy_score'),
                'norm_coverage_score' => $this->nullableScore($request, 'norm_coverage_score'),
                'case_law_score' => $this->nullableScore($request, 'case_law_score'),
                'source_routing_score' => $this->nullableScore($request, 'source_routing_score'),
                'clarity_score' => $this->nullableScore($request, 'clarity_score'),
                'verdict' => $request->string('verdict')->toString(),
                'used_norms_snapshot' => $this->usedNormsSnapshot($message),
                'correct_norms' => $request->input('correct_norms', []),
                'incorrect_norms' => $request->input('incorrect_norms', []),
                'missing_norms' => $request->input('missing_norms', []),
                'used_cases_snapshot' => $this->usedCasesSnapshot($message),
                'correct_cases' => $request->input('correct_cases', []),
                'irrelevant_cases' => $request->input('irrelevant_cases', []),
                'missing_cases' => $request->input('missing_cases', []),
                'requested_sources_snapshot' => $this->requestedSourcesSnapshot($message),
                'used_sources_snapshot' => $this->usedSourcesSnapshot($message),
                'source_checks' => $request->input('source_checks', []),
                'improvement_actions' => $request->input('improvement_actions', []),
                'notes' => $request->input('notes'),
            ],
        );

        return response()->json([
            'data' => $this->formatReview($review->fresh()),
        ], $review->wasRecentlyCreated ? 201 : 200);
    }

    private function authorizeMessageAccess(?User $user, ChatMessage $message): void
    {
        abort_if($user === null, 401);

        $message->loadMissing('chat');

        $ownsChat = (int) $message->chat?->user_id === (int) $user->id;
        abort_unless($ownsChat, 404);
    }

    private function nullableScore(StoreLegalAnswerReviewRequest $request, string $key): ?int
    {
        return $request->filled($key) ? $request->integer($key) : null;
    }

    private function usedNormsSnapshot(ChatMessage $message): array
    {
        $meta = $message->meta ?? [];

        return [
            'law_citations' => $this->compactItems($meta['law_citations'] ?? [], [
                'type',
                'title',
                'article_num',
                'article_title',
                'url',
            ]),
            'matsne_citations' => $this->compactItems($meta['matsne_citations'] ?? [], [
                'type',
                'title',
                'article_num',
                'doc_type',
                'issuer',
                'is_active',
                'url',
            ]),
        ];
    }

    private function usedCasesSnapshot(ChatMessage $message): array
    {
        $meta = $message->meta ?? [];

        return [
            'court' => $this->compactItems($meta['citations'] ?? [], [
                'case_id',
                'case_num',
                'case_date',
                'court',
                'chamber',
                'category',
                'dispute_subject',
                'result',
                'url',
            ]),
            'echr' => $this->compactItems($meta['echr_citations'] ?? [], [
                'application_no',
                'case_name',
                'judgment_date',
                'articles_violated',
                'url',
            ]),
            'eu' => $this->compactItems($meta['eu_citations'] ?? [], [
                'cellar_id',
                'doc_type',
                'source',
                'court',
                'case_num',
                'title',
                'doc_date',
                'url',
            ]),
            'german' => $this->compactItems($meta['german_citations'] ?? [], [
                'case_id',
                'external_id',
                'court_name',
                'level_of_appeal',
                'jurisdiction',
                'date_year',
            ]),
            'const_court' => $this->compactItems($meta['const_court_citations'] ?? [], [
                'legal_id',
                'case_number',
                'case_name',
                'decision_type',
                'decision_date',
                'college',
                'respondent',
                'result',
                'url',
            ]),
        ];
    }

    private function requestedSourcesSnapshot(ChatMessage $message): array
    {
        $meta = $message->meta ?? [];

        $requested = $meta['requested_sources'] ?? [];
        if (!is_array($requested)) {
            $requested = [];
        }

        $active = $this->normalizeActiveSources($meta['sources_active'] ?? []);
        $statusSources = [];
        foreach (($meta['source_status'] ?? []) as $source => $status) {
            if (($status['requested'] ?? false) || ($status['routed'] ?? false)) {
                $statusSources[] = $source;
            }
        }

        return array_values(array_unique(array_filter([
            ...$requested,
            ...$active,
            ...$statusSources,
        ])));
    }

    private function usedSourcesSnapshot(ChatMessage $message): array
    {
        $meta = $message->meta ?? [];

        $counts = [
            'court' => count($meta['citations'] ?? []),
            'matsne' => count($meta['matsne_citations'] ?? []) + count($meta['law_citations'] ?? []),
            'echr' => count($meta['echr_citations'] ?? []),
            'eu' => count($meta['eu_citations'] ?? []),
            'german' => count($meta['german_citations'] ?? []),
            'const_court' => count($meta['const_court_citations'] ?? []),
        ];

        return array_filter($counts, static fn (int $count): bool => $count > 0);
    }

    private function normalizeActiveSources(mixed $sources): array
    {
        if (!is_array($sources)) {
            return [];
        }

        return array_map(static fn (string $source): string => match ($source) {
            'domestic' => 'court',
            'law' => 'matsne',
            default => $source,
        }, $sources);
    }

    private function compactItems(array $items, array $keys): array
    {
        return array_map(static function (array $item) use ($keys): array {
            return array_intersect_key($item, array_flip($keys));
        }, $items);
    }

    private function formatReview(LegalAnswerReview $review): array
    {
        return [
            'id' => $review->id,
            'chat_id' => $review->chat_id,
            'chat_message_id' => $review->chat_message_id,
            'reviewer_id' => $review->reviewer_id,
            'reviewer_name' => $review->reviewer_name,
            'overall_score' => $review->overall_score,
            'legal_accuracy_score' => $review->legal_accuracy_score,
            'norm_coverage_score' => $review->norm_coverage_score,
            'case_law_score' => $review->case_law_score,
            'source_routing_score' => $review->source_routing_score,
            'clarity_score' => $review->clarity_score,
            'verdict' => $review->verdict,
            'used_norms_snapshot' => $review->used_norms_snapshot ?? [],
            'correct_norms' => $review->correct_norms ?? [],
            'incorrect_norms' => $review->incorrect_norms ?? [],
            'missing_norms' => $review->missing_norms ?? [],
            'used_cases_snapshot' => $review->used_cases_snapshot ?? [],
            'correct_cases' => $review->correct_cases ?? [],
            'irrelevant_cases' => $review->irrelevant_cases ?? [],
            'missing_cases' => $review->missing_cases ?? [],
            'requested_sources_snapshot' => $review->requested_sources_snapshot ?? [],
            'used_sources_snapshot' => $review->used_sources_snapshot ?? [],
            'source_checks' => $review->source_checks ?? [],
            'improvement_actions' => $review->improvement_actions ?? [],
            'notes' => $review->notes,
            'created_at' => $review->created_at?->toISOString(),
            'updated_at' => $review->updated_at?->toISOString(),
        ];
    }
}
