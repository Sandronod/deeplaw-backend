<?php

namespace App\Services\Legal;

use App\DTOs\ParsedQuery;
use App\DTOs\RetrievalResult;
use App\Models\LegalCase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class LegalCaseRetrieverService
{
    /**
     * Full retrieval pipeline.
     *
     * @param  array       $rawEmbedding   Float array — raw query embedding (always required)
     * @param  string      $searchTerms    Extracted clean terms → metadata ILIKE
     * @param  string      $originalQuery  Full user question → case_num/year extraction fallback
     * @param  array|null  $hydeEmbedding  HyDE embedding for dual search (optional)
     * @param  ParsedQuery|null $parsed    Structured filters from QueryParserService (optional)
     */
    public function retrieve(
        array       $rawEmbedding,
        string      $searchTerms  = '',
        string      $originalQuery = '',
        ?array      $hydeEmbedding = null,
        ?ParsedQuery $parsed       = null,
    ): RetrievalResult {
        $chunkLimit = config('openai.retrieval_chunk_limit', 20);
        $caseLimit  = config('openai.retrieval_case_limit', 3);
        $baseScore  = config('openai.retrieval_min_score', 0.65);

        // ── 0a. Resolve year filter ───────────────────────────────────────────
        $year = $parsed?->effectiveYear();
        if ($year === null && preg_match('/\b(19|20)\d{2}\b/', $originalQuery, $m)) {
            $y = (int) $m[0];
            if ($y >= 1990 && $y <= (int) date('Y')) {
                $year = $y;
            }
        }

        // ── 0b. Case number direct lookup ─────────────────────────────────────
        $caseNumChunks = collect();
        $caseNumPattern = $parsed?->caseNumber;
        if ($caseNumPattern === null) {
            preg_match('/[ა-ჰA-Z]{1,4}[-\/]\d+(?:\([^)]+\))?/u', $originalQuery, $cn);
            $caseNumPattern = $cn[0] ?? null;
        }
        if ($caseNumPattern !== null) {
            $caseNumChunks = LegalCase::metadataSearch($caseNumPattern, 5, null);
            Log::debug('Retriever: case number lookup', [
                'pattern' => $caseNumPattern,
                'found'   => $caseNumChunks->count(),
            ]);
        }

        // ── 1. Vector search — skipped when provider has no embeddings ───────
        $thresholds = [$baseScore, 0.50, 0.40];
        $rawChunks  = collect();
        $useVector  = !empty($rawEmbedding) && config('ai.provider', 'openai') !== 'gemini';

        if ($useVector) {
            foreach ($thresholds as $minScore) {
                $rawChunks = LegalCase::vectorSearch($rawEmbedding, $chunkLimit, $minScore, $year);
                if ($rawChunks->isNotEmpty()) {
                    Log::debug('Retriever: raw vector search', [
                        'threshold' => $minScore,
                        'found'     => $rawChunks->count(),
                    ]);
                    break;
                }
            }
        }

        // ── 2. Vector search — HyDE embedding (if provided) ──────────────────
        $hydeChunks = collect();
        if ($useVector && $hydeEmbedding !== null) {
            foreach ($thresholds as $minScore) {
                $hydeChunks = LegalCase::vectorSearch($hydeEmbedding, $chunkLimit, $minScore, $year);
                if ($hydeChunks->isNotEmpty()) {
                    Log::debug('Retriever: HyDE vector search', [
                        'threshold' => $minScore,
                        'found'     => $hydeChunks->count(),
                    ]);
                    break;
                }
            }
        }

        // ── 3. Merge raw + HyDE by chunk id, keep max similarity ─────────────
        $vectorChunks = $this->mergeChunkResults($rawChunks, $hydeChunks);

        // ── 4. Metadata search — uses searchTerms or judge filter ─────────────
        $metaChunks  = collect();
        $metaTerms   = $parsed?->judge ?? $searchTerms;
        if (!empty($metaTerms)) {
            $metaChunks = LegalCase::metadataSearch($metaTerms, 30, $year);
            Log::debug('Retriever: metadata search', [
                'terms' => $metaTerms,
                'found' => $metaChunks->count(),
            ]);
        }

        // ── 5. Three-way merge: case_num (1.0) > vector > metadata (0.60) ────
        $matchedChunks   = $vectorChunks;
        $existingCaseIds = $vectorChunks->pluck('case_id')->unique()->toArray();

        if ($caseNumChunks->isNotEmpty()) {
            $newCnIds = array_diff(
                $caseNumChunks->pluck('case_id')->unique()->toArray(),
                $existingCaseIds
            );
            if (!empty($newCnIds)) {
                $extra = $caseNumChunks->whereIn('case_id', $newCnIds)->map(function ($c) {
                    $c->similarity = 1.0;
                    return $c;
                });
                $matchedChunks   = $matchedChunks->concat($extra);
                $existingCaseIds = array_merge($existingCaseIds, $newCnIds);
            }
        }

        if ($metaChunks->isNotEmpty()) {
            $newMetaIds = array_diff(
                $metaChunks->pluck('case_id')->unique()->toArray(),
                $existingCaseIds
            );
            if (!empty($newMetaIds)) {
                $extra = $metaChunks->whereIn('case_id', $newMetaIds)->map(function ($c) {
                    $c->similarity = 0.60;
                    return $c;
                });
                $matchedChunks = $matchedChunks->concat($extra);
            }
        }

        if ($matchedChunks->isEmpty()) {
            Log::debug('Retriever: no results found');
            return RetrievalResult::empty();
        }

        // ── 6. Dynamic case limit (more metadata matches → expand limit) ──────
        $metaUniqueCases = $metaChunks->pluck('case_id')->unique()->count();
        if ($metaUniqueCases > $caseLimit) {
            $caseLimit = min(30, $metaUniqueCases);
        }

        // ── 7. Score + select top N cases (expanded for reranker) ─────────────
        // Retrieve up to 3× the default limit so the reranker has room to work
        $retrievalLimit = min(30, $caseLimit * 3);
        $caseScores     = $this->computeCaseScores($matchedChunks);

        $topCaseIds = $caseScores
            ->sortByDesc('score')
            ->take($retrievalLimit)
            ->pluck('case_id')
            ->toArray();

        // ── 8. Reconstruct decisions ──────────────────────────────────────────
        $allChunks = LegalCase::chunksForCases($topCaseIds);

        $matchedChunksByCase = $matchedChunks
            ->whereIn('case_id', $topCaseIds)
            ->groupBy('case_id')
            ->map(fn($g) => $g->pluck('content')->filter()->unique()->values()->toArray());

        [$decisions, $qualityFlags] = $this->reconstructDecisions(
            $allChunks,
            $caseScores,
            $topCaseIds,
            $matchedChunksByCase,
        );

        $matchedCaseNumbers = collect($decisions)->pluck('case_num')->filter()->unique()->values()->toArray();
        $relevanceScores    = $caseScores
            ->whereIn('case_id', $topCaseIds)
            ->pluck('score', 'case_id')
            ->toArray();

        Log::debug('Retriever: complete', [
            'candidates'  => count($topCaseIds),
            'chunks'      => $allChunks->count(),
            'hyde_used'   => $hydeEmbedding !== null,
            'dual_chunks' => $hydeChunks->count(),
        ]);

        return new RetrievalResult(
            decisions:          $decisions,
            matchedCaseIds:     $topCaseIds,
            matchedCaseNumbers: $matchedCaseNumbers,
            relevanceScores:    $relevanceScores,
            usedChunkCount:     $allChunks->count(),
            usedCaseCount:      count($topCaseIds),
            totalMetaFound:     $metaUniqueCases,
        );
    }

    public function emptyRetrieval(): RetrievalResult
    {
        return RetrievalResult::empty();
    }

    // ── Private: merge ────────────────────────────────────────────────────────

    /**
     * Merges two chunk collections by chunk row id, keeping max similarity.
     * This handles the dual-embedding case: same physical chunk may appear in
     * both raw and HyDE results with different similarity scores.
     */
    private function mergeChunkResults(Collection $primary, Collection $secondary): Collection
    {
        if ($secondary->isEmpty()) {
            return $primary;
        }
        if ($primary->isEmpty()) {
            return $secondary;
        }

        // Index primary by row id
        $merged = $primary->keyBy('id');

        foreach ($secondary as $chunk) {
            $existing = $merged->get($chunk->id);
            if ($existing === null) {
                $merged->put($chunk->id, $chunk);
            } elseif ((float) $chunk->similarity > (float) $existing->similarity) {
                // Keep higher similarity from either embedding
                $merged->put($chunk->id, $chunk);
            }
        }

        return $merged->values();
    }

    // ── Private: scoring ──────────────────────────────────────────────────────

    private function computeCaseScores(Collection $chunks): Collection
    {
        return $chunks
            ->groupBy('case_id')
            ->map(function (Collection $group, int $caseId) {
                $similarities = $group->pluck('similarity')->map('floatval');
                return [
                    'case_id'        => $caseId,
                    'score'          => 0.7 * $similarities->max() + 0.3 * $similarities->avg(),
                    'max_sim'        => $similarities->max(),
                    'avg_sim'        => $similarities->avg(),
                    'matched_chunks' => $group->count(),
                ];
            })
            ->values();
    }

    // ── Private: reconstruction ───────────────────────────────────────────────

    /**
     * Reconstructs decision arrays from raw chunks.
     *
     * Improvements over previous version:
     *  - Deduplicates chunks by content hash (prevents repeated content)
     *  - Detects chunk sequence gaps (missing chunks in decision)
     *  - Tracks per-decision quality flags
     *
     * @return array{0: array[], 1: array<int, string[]>} [$decisions, $qualityFlagsByCaseId]
     */
    private function reconstructDecisions(
        Collection $allChunks,
        Collection $caseScores,
        array      $orderedCaseIds,
        Collection $matchedChunksByCase,
    ): array {
        $scoresByCaseId = $caseScores->keyBy('case_id');
        $chunksByCaseId = $allChunks->groupBy('case_id');
        $decisions      = [];
        $qualityFlags   = [];

        foreach ($orderedCaseIds as $caseId) {
            $chunks = $chunksByCaseId->get($caseId, collect());
            if ($chunks->isEmpty()) {
                continue;
            }

            // Deduplicate by content hash
            $seen        = [];
            $uniqueChunks = $chunks->filter(function ($c) use (&$seen) {
                $hash = md5($c->content ?? '');
                if (isset($seen[$hash])) {
                    return false;
                }
                $seen[$hash] = true;
                return true;
            });

            // Detect sequence gaps using chunk_index from meta
            $flags = $this->detectQualityFlags($uniqueChunks);
            $qualityFlags[$caseId] = $flags;

            $first    = $uniqueChunks->first();
            $fullText = $uniqueChunks
                ->map(fn($c) => trim($c->content ?? ''))
                ->filter()
                ->implode("\n\n");

            // Excerpt: deduplicated matched chunk contents
            $rawMatched = $matchedChunksByCase->get($caseId, []);
            $rawMatched = array_unique($rawMatched);
            $excerpt    = !empty($rawMatched)
                ? implode("\n\n", $rawMatched)
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
                'case_type'       => $first->case_type ?? 'administrative',
                'full_text'       => $fullText,
                'excerpt'         => $excerpt,
                'chunk_count'     => $uniqueChunks->count(),
                'quality_flags'   => $flags,
                'relevance_score' => $score ? round($score['score'], 4) : null,
            ];
        }

        return [$decisions, $qualityFlags];
    }

    /**
     * Detects quality issues in the chunk sequence for a decision.
     * Returns array of flag strings (empty = clean).
     */
    private function detectQualityFlags(Collection $chunks): array
    {
        $flags = [];

        // Extract chunk indices from meta
        $indices = $chunks
            ->map(fn($c) => $c->chunk_index !== null
                ? (int) $c->chunk_index
                : null)
            ->filter(fn($v) => $v !== null)
            ->sort()
            ->values();

        if ($indices->isEmpty()) {
            return $flags; // No index metadata — can't assess
        }

        $min      = $indices->first();
        $max      = $indices->last();
        $expected = range($min, $max);
        $actual   = $indices->toArray();
        $missing  = array_diff($expected, $actual);

        if (!empty($missing)) {
            $flags[] = 'missing_chunks:' . implode(',', array_values($missing));
        }

        // Flag if we only got a partial view (starts after chunk 0)
        if ($min > 0) {
            $flags[] = 'starts_at_chunk:' . $min;
        }

        return $flags;
    }
}
