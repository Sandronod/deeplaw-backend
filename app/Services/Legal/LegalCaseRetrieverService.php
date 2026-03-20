<?php

namespace App\Services\Legal;

use App\Models\LegalCase;
use Illuminate\Support\Collection;

class LegalCaseRetrieverService
{
    /**
     * Full retrieval pipeline.
     *
     * Returns array of reconstructed decisions with metadata.
     *
     * @param  array  $embedding   Float array from OpenAI
     * @return array{
     *   decisions: array,
     *   matched_case_ids: array,
     *   matched_case_numbers: array,
     *   relevance_scores: array,
     *   used_chunk_count: int,
     *   used_case_count: int,
     * }
     */
    public function retrieve(array $embedding, string $rawQuery = ''): array
    {
        $chunkLimit = config('openai.retrieval_chunk_limit', 20);
        $caseLimit  = config('openai.retrieval_case_limit', 3);
        $baseScore  = config('openai.retrieval_min_score', 0.65);

        // ── 1. Vector search (tiered threshold) ──────────────────────────────
        $thresholds    = [$baseScore, 0.50, 0.40];
        $vectorChunks  = collect();

        foreach ($thresholds as $minScore) {
            $vectorChunks = LegalCase::vectorSearch($embedding, $chunkLimit, $minScore);
            if ($vectorChunks->isNotEmpty()) {
                break;
            }
        }

        // ── 2. Metadata search — case_num, court, chamber, category… ─────────
        $metaChunks = collect();
        if (!empty($rawQuery)) {
            $metaChunks = LegalCase::metadataSearch($rawQuery, 30);
        }

        // ── 3. Merge: metadata case_ids ემატება vector შედეგებს ──────────────
        $matchedChunks = $vectorChunks;

        if ($metaChunks->isNotEmpty()) {
            $metaCaseIds    = $metaChunks->pluck('case_id')->unique()->toArray();
            $vectorCaseIds  = $vectorChunks->pluck('case_id')->unique()->toArray();
            $newCaseIds     = array_diff($metaCaseIds, $vectorCaseIds);

            if (!empty($newCaseIds)) {
                // metadata-ით ნაპოვნი case_ids-ის chunks-ებს ვამატებთ
                // similarity=0.60 dummy score-ით (metadata match-ისთვის)
                $extraChunks = $metaChunks
                    ->whereIn('case_id', $newCaseIds)
                    ->map(function ($c) {
                        $c->similarity = 0.60;
                        return $c;
                    });
                $matchedChunks = $vectorChunks->concat($extraChunks);
            }
        }

        if ($matchedChunks->isEmpty()) {
            return $this->emptyResult();
        }

        // ── Dynamic case limit ──────────────────────────────────────────────
        // მოსამართლის/მხარის სახელზე ძებნა: vector ვერ პოულობს, meta პოულობს.
        // ასეთ შემთხვევაში მეტ case-ს ვაბრუნებთ (max 10 vs ჩვეული 3).
        // meta-ით ნაპოვნი unique case-ების რაოდენობა config caseLimit-ს აჭარბებს?
        // → limit-ს ვაფართოვებთ (სახელი/ობიექტი ძებნა vs. თემური ძებნა)
        $metaUniqueCases = $metaChunks->pluck('case_id')->unique()->count();
        if ($metaUniqueCases > $caseLimit) {
            $caseLimit = min(30, $metaUniqueCases);
        }

        // Step 2: Group by case_id — compute aggregate relevance score
        $caseScores = $this->computeCaseScores($matchedChunks);

        // Step 3: Pick top N parent decisions by aggregate score
        $topCaseIds = $caseScores
            ->sortByDesc('score')
            ->take($caseLimit)
            ->pluck('case_id')
            ->toArray();

        // Step 4: Fetch ALL chunks for those case_ids, ordered correctly
        $allChunks = LegalCase::chunksForCases($topCaseIds);

        // Step 5: Reconstruct decisions — matched chunks first, then rest
        // matched chunks-ს ინახავს case_id → [chunk_content, ...] სახით
        $matchedChunksByCase = $matchedChunks
            ->whereIn('case_id', $topCaseIds)
            ->groupBy('case_id')
            ->map(fn ($g) => $g->pluck('content')->filter()->values()->toArray());

        $decisions = $this->reconstructDecisions($allChunks, $caseScores, $topCaseIds, $matchedChunksByCase);

        // Step 6: Build return metadata
        $matchedCaseNumbers = collect($decisions)->pluck('case_num')->filter()->unique()->values()->toArray();
        $relevanceScores    = $caseScores->whereIn('case_id', $topCaseIds)
            ->pluck('score', 'case_id')->toArray();

        return [
            'decisions'            => $decisions,
            'matched_case_ids'     => $topCaseIds,
            'matched_case_numbers' => $matchedCaseNumbers,
            'relevance_scores'     => $relevanceScores,
            'used_chunk_count'     => $allChunks->count(),
            'used_case_count'      => count($topCaseIds),
            'total_meta_found'     => $metaUniqueCases, // სულ რამდენი case იპოვა meta search-ით
        ];
    }

    /**
     * Groups chunks by case_id and computes a weighted relevance score per case.
     * Score = 0.7 * max_similarity + 0.3 * avg_similarity
     */
    private function computeCaseScores(Collection $chunks): Collection
    {
        return $chunks
            ->groupBy('case_id')
            ->map(function (Collection $group, int $caseId) {
                $similarities  = $group->pluck('similarity');
                $maxSimilarity = $similarities->max();
                $avgSimilarity = $similarities->avg();

                return [
                    'case_id'       => $caseId,
                    'score'         => 0.7 * $maxSimilarity + 0.3 * $avgSimilarity,
                    'max_sim'       => $maxSimilarity,
                    'avg_sim'       => $avgSimilarity,
                    'matched_chunks' => $group->count(),
                ];
            })
            ->values();
    }

    /**
     * Reconstructs decisions.
     * - full_text  : სრული გადაწყვეტილება (ყველა chunk, სწორი თანმიმდევრობა)
     * - excerpt    : მხოლოდ matched chunks — OpenAI-სთვის პირველ რიგში გამოიყენება
     */
    private function reconstructDecisions(
        Collection $allChunks,
        Collection $caseScores,
        array      $orderedCaseIds,
        Collection $matchedChunksByCase = null,
    ): array {
        $scoresByCaseId = $caseScores->keyBy('case_id');
        $chunksByCaseId = $allChunks->groupBy('case_id');

        $decisions = [];

        foreach ($orderedCaseIds as $caseId) {
            $chunks = $chunksByCaseId->get($caseId, collect());

            if ($chunks->isEmpty()) {
                continue;
            }

            $first = $chunks->first();

            // სრული ტექსტი — ყველა chunk სწორი თანმიმდევრობა
            $fullText = $chunks
                ->map(fn ($c) => $c->content ?? '')
                ->filter()
                ->implode("\n\n");

            // Excerpt — matched chunks (query-ს ყველაზე releval ნაწილი)
            $matchedContents = $matchedChunksByCase?->get($caseId, []) ?? [];
            $excerpt = !empty($matchedContents)
                ? implode("\n\n", $matchedContents)
                : mb_substr($fullText, 0, 4000);

            $score = $scoresByCaseId->get($caseId);

            $decisions[] = [
                'case_id'         => $caseId,
                'case_num'        => $first->case_num,
                'dispute_subject' => $first->dispute_subject,
                'case_date'       => $first->case_date,
                'category'        => $first->category,
                'result'          => $first->result,
                'claim_type'      => $first->claim_type,
                'kind'            => $first->kind,
                'chamber'         => $first->chamber,
                'court'           => $first->court,
                'section'         => $first->section,
                'full_text'       => $fullText,
                'excerpt'         => $excerpt,         // matched chunks only
                'chunk_count'     => $chunks->count(),
                'relevance_score' => $score ? round($score['score'], 4) : null,
            ];
        }

        return $decisions;
    }

    public function emptyRetrieval(): array
    {
        return $this->emptyResult();
    }

    private function emptyResult(): array
    {
        return [
            'decisions'            => [],
            'matched_case_ids'     => [],
            'matched_case_numbers' => [],
            'relevance_scores'     => [],
            'used_chunk_count'     => 0,
            'used_case_count'      => 0,
            'total_meta_found'     => 0,
        ];
    }
}
