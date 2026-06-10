<?php

namespace App\Services\Legal;

use App\DTOs\ParsedQuery;
use App\DTOs\RetrievalResult;
use App\Models\LegalCase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class LegalCaseRetrieverService
{
    private const CASE_EMBEDDING_SCORE_BOOST = 1.15;
    private const CASE_CARD_SCORE_FLOOR = 0.62;
    private const CASE_CARD_SCORE_BOOST = 0.34;

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
        ?string     $caseType     = null,
        bool        $skipCaseVector = false,
        ?array      $fingerprintEmbedding = null,
    ): RetrievalResult {
        $chunkLimit = config('openai.retrieval_chunk_limit', 20);
        $caseLimit  = config('openai.retrieval_case_limit', 3);
        $baseScore  = config('openai.retrieval_min_score', 0.65);
        $rankingQuery = trim($searchTerms) !== '' ? $searchTerms : $originalQuery;

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

        // ── 0c. Lexical pasted-text search — exact-ish fallback for long excerpts ──
        $pastedTextChunks = collect();
        if (mb_strlen($originalQuery) > 500) {
            $pastedTextChunks = LegalCase::pastedTextSearch($originalQuery, 10, $caseType);
            Log::warning('Retriever: pasted-text lexical search', [
                'hits' => $pastedTextChunks->count(),
                'case_ids' => $pastedTextChunks->pluck('case_id')->unique()->values()->toArray(),
            ]);
        }

        // ── 0d. Fingerprint search — near-duplicate detection for pasted text ──
        // When the user pastes a long decision excerpt, the first 300 chars are
        // embedded separately and searched at threshold 0.90. A hit means the
        // exact (or near-identical) chunk is in the DB — surface that case first.
        $fingerprintChunks = collect();
        if (!empty($fingerprintEmbedding)) {
            $fingerprintChunks = LegalCase::vectorSearch($fingerprintEmbedding, 5, 0.82, null, $caseType);
            Log::warning('Retriever: fingerprint search', [
                'hits' => $fingerprintChunks->count(),
                'max'  => $fingerprintChunks->isNotEmpty() ? $fingerprintChunks->max('similarity') : null,
                'case_ids' => $fingerprintChunks->pluck('case_id')->unique()->values()->toArray(),
            ]);
        }

        // ── 1. Vector search — skipped when provider has no embeddings ───────
        $thresholds = [$baseScore, 0.50, 0.40];
        $rawChunks  = collect();
        $useVector  = !empty($rawEmbedding) && config('ai.provider', 'openai') !== 'gemini';

        if ($useVector) {
            foreach ($thresholds as $minScore) {
                $rawChunks = LegalCase::vectorSearch($rawEmbedding, $chunkLimit, $minScore, $year, $caseType);
                if ($rawChunks->isNotEmpty()) {
                    Log::debug('Retriever: raw vector search', [
                        'threshold' => $minScore,
                        'case_type' => $caseType,
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
                $hydeChunks = LegalCase::vectorSearch($hydeEmbedding, $chunkLimit, $minScore, $year, $caseType);
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
        $metaChunks     = collect();
        $metaTerms      = $parsed?->judge ?? $searchTerms;
        $includeContent = $parsed?->judge !== null; // content ILIKE only for judge name lookups
        if (!empty($metaTerms)) {
            $metaChunks = LegalCase::metadataSearch($metaTerms, 30, $year, $caseType, $includeContent);
            Log::debug('Retriever: metadata search', [
                'terms'          => $metaTerms,
                'include_content'=> $includeContent,
                'case_type'      => $caseType,
                'found'          => $metaChunks->count(),
            ]);
        }

        // ── 4a. Case-card lexical rescue ─────────────────────────────────────
        // Structured case_card legal_issue/holding can be an exact match even
        // when vector search ranks the case below the candidate cut.
        $caseCardChunks = collect();
        if (!empty($rankingQuery)) {
            $caseCardChunks = LegalCase::caseCardKeywordSearch($rankingQuery, 30, $caseType);
            if ($caseCardChunks->isNotEmpty()) {
                Log::debug('Retriever: case-card keyword search', [
                    'found' => $caseCardChunks->count(),
                    'case_type' => $caseType,
                    'case_ids' => $caseCardChunks->pluck('case_id')->values()->toArray(),
                ]);
            }
        }

        // ── 4b. Case-level embedding search (concept-level) ───────────────────
        // Searches court_cases.case_embedding (bge-m3 over legal_issue + articles).
        // Finds cases where the legal concept matches even if raw chunk text doesn't.
        $caseChunks = collect();
        if ($useVector && !empty($rawEmbedding) && !$skipCaseVector) {
            $caseChunks = LegalCase::caseEmbeddingSearch($rawEmbedding, 30, 0.60, $caseType);
            if ($hydeEmbedding !== null) {
                $caseChunks = $this->mergeChunkResults(
                    $caseChunks,
                    LegalCase::caseEmbeddingSearch($hydeEmbedding, 30, 0.60, $caseType),
                );
            }
            if ($caseType === null) {
                foreach (['administrative', 'civil', 'criminal'] as $balancedType) {
                    $caseChunks = $this->mergeChunkResults(
                        $caseChunks,
                        LegalCase::caseEmbeddingSearch($rawEmbedding, 30, 0.60, $balancedType),
                    );
                    if ($hydeEmbedding !== null) {
                        $caseChunks = $this->mergeChunkResults(
                            $caseChunks,
                            LegalCase::caseEmbeddingSearch($hydeEmbedding, 30, 0.60, $balancedType),
                        );
                    }
                }
            }
            if ($caseChunks->isNotEmpty()) {
                Log::debug('Retriever: case-level embedding search', [
                    'found'     => $caseChunks->count(),
                    'case_type' => $caseType,
                ]);
            }
        }

        // ── 5. Merge: pasted text / fingerprint / case_num dominate semantic hits ──
        $matchedChunks   = $vectorChunks;
        $existingCaseIds = $vectorChunks->pluck('case_id')->unique()->toArray();

        if ($pastedTextChunks->isNotEmpty()) {
            $newPastedIds = array_diff(
                $pastedTextChunks->pluck('case_id')->unique()->toArray(),
                $existingCaseIds
            );
            if (!empty($newPastedIds)) {
                $extra = $pastedTextChunks->whereIn('case_id', $newPastedIds)->map(function ($c) {
                    $c->similarity = max(0.60, (float) ($c->similarity ?? 0.0));
                    $c->match_source = 'pasted_text';
                    return $c;
                });
                $matchedChunks   = $matchedChunks->concat($extra);
                $existingCaseIds = array_merge($existingCaseIds, $newPastedIds);
            }
        }

        if ($fingerprintChunks->isNotEmpty()) {
            $newFpIds = array_diff(
                $fingerprintChunks->pluck('case_id')->unique()->toArray(),
                $existingCaseIds
            );
            if (!empty($newFpIds)) {
                $extra = $fingerprintChunks->whereIn('case_id', $newFpIds)->map(function ($c) {
                    $c->similarity = 1.0;
                    $c->match_source = 'fingerprint';
                    return $c;
                });
                $matchedChunks   = $matchedChunks->concat($extra);
                $existingCaseIds = array_merge($existingCaseIds, $newFpIds);
            }
        }

        if ($caseNumChunks->isNotEmpty()) {
            $newCnIds = array_diff(
                $caseNumChunks->pluck('case_id')->unique()->toArray(),
                $existingCaseIds
            );
            if (!empty($newCnIds)) {
                $extra = $caseNumChunks->whereIn('case_id', $newCnIds)->map(function ($c) {
                    $c->similarity = 1.0;
                    $c->match_source = 'case_number';
                    return $c;
                });
                $matchedChunks   = $matchedChunks->concat($extra);
                $existingCaseIds = array_merge($existingCaseIds, $newCnIds);
            }
        }

        if ($caseChunks->isNotEmpty()) {
            $newCaseIds = array_diff(
                $caseChunks->pluck('case_id')->unique()->toArray(),
                $existingCaseIds
            );
            // Concat ALL case-vector results, not just new ones.
            // For cases already found by chunk-vector, the case_embedding similarity
            // becomes an additional data point: raises max_sim when case_sim > chunk_sim,
            // and lowers avg_sim for noise cases where chunk_sim > case_sim.
            $matchedChunks   = $matchedChunks->concat($caseChunks);
            $existingCaseIds = array_merge($existingCaseIds, $newCaseIds);
        }

        if ($caseCardChunks->isNotEmpty()) {
            $newCardIds = array_diff(
                $caseCardChunks->pluck('case_id')->unique()->toArray(),
                $existingCaseIds
            );

            $matchedChunks = $matchedChunks->concat($caseCardChunks);
            $existingCaseIds = array_merge($existingCaseIds, $newCardIds);
        }

        if ($metaChunks->isNotEmpty()) {
            $newMetaIds = array_diff(
                $metaChunks->pluck('case_id')->unique()->toArray(),
                $existingCaseIds
            );
            if (!empty($newMetaIds)) {
                $extra = $metaChunks->whereIn('case_id', $newMetaIds)->map(function ($c) {
                    $c->similarity = 0.60;
                    $c->match_source = 'metadata';
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
        // Retrieve a wider internal candidate set so semantic scoring can choose
        // among legally analogous decisions. The final answer still receives top K.
        $retrievalLimit = min(30, max($caseLimit * 6, 18));
        $caseScores     = $this->computeCaseScores($matchedChunks, $rankingQuery);

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
            'candidates'       => count($topCaseIds),
            'chunks'           => $allChunks->count(),
            'hyde_used'        => $hydeEmbedding !== null,
            'dual_chunks'      => $hydeChunks->count(),
            'case_level_hits'  => $caseChunks->count(),
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

    private function computeCaseScores(Collection $chunks, string $originalQuery = ''): Collection
    {
        return $chunks
            ->groupBy('case_id')
            ->map(function (Collection $group, int $caseId) use ($originalQuery) {
                $similarities = $group->pluck('similarity')->map('floatval');
                $caseSimilarities = $group
                    ->filter(fn ($chunk) => ($chunk->match_source ?? null) === 'case_embedding')
                    ->pluck('similarity')
                    ->map('floatval');

                $score = 0.7 * $similarities->max() + 0.3 * $similarities->avg();
                $cardScore = $this->caseCardTextScore($originalQuery, $group->first());

                if ($caseSimilarities->isNotEmpty()) {
                    $score = max($score, min(1.0, $caseSimilarities->max() * self::CASE_EMBEDDING_SCORE_BOOST));
                }

                if ($cardScore > 0) {
                    $score = max($score, min(1.0, self::CASE_CARD_SCORE_FLOOR + self::CASE_CARD_SCORE_BOOST * $cardScore));
                }

                return [
                    'case_id'         => $caseId,
                    'score'           => $score,
                    'max_sim'         => $similarities->max(),
                    'avg_sim'         => $similarities->avg(),
                    'case_sim'        => $caseSimilarities->isNotEmpty() ? $caseSimilarities->max() : null,
                    'case_card_score' => $cardScore,
                    'match_sources'   => $group->pluck('match_source')->filter()->unique()->values()->toArray(),
                    'matched_chunks'  => $group->count(),
                ];
            })
            ->values();
    }

    private function caseCardTextScore(string $query, ?object $row): float
    {
        $queryTokens = $this->meaningfulTokens($query);
        if (empty($queryTokens) || $row === null || empty($row->case_card)) {
            return 0.0;
        }

        $card = is_string($row->case_card)
            ? json_decode($row->case_card, true)
            : (array) $row->case_card;

        if (!is_array($card)) {
            return 0.0;
        }

        $legalIssue = $this->tokenCoverage($queryTokens, $this->meaningfulTokens((string) ($card['legal_issue'] ?? '')));
        $holding    = $this->tokenCoverage($queryTokens, $this->meaningfulTokens((string) ($card['holding'] ?? '')));
        $category   = $this->tokenCoverage($queryTokens, $this->meaningfulTokens((string) ($row->category ?? '')));

        return max($legalIssue, $holding * 0.75, $category * 0.45);
    }

    private function tokenCoverage(array $queryTokens, array $candidateTokens): float
    {
        if (empty($queryTokens) || empty($candidateTokens)) {
            return 0.0;
        }

        $candidateSet = array_fill_keys($candidateTokens, true);
        $matched = 0;

        foreach ($queryTokens as $token) {
            if (isset($candidateSet[$token])) {
                $matched++;
            }
        }

        return $matched / count($queryTokens);
    }

    private function meaningfulTokens(string $text): array
    {
        $lower = mb_strtolower($text);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $lower) ?: [];

        $stop = [
            'და', 'ან', 'თუ', 'რომ', 'არის', 'იყო', 'იქნა', 'საქმე', 'საკითხი',
            'თაობაზე', 'შესახებ', 'სასამართლო', 'სასამართლოს', 'საქართველოს',
        ];
        $stopSet = array_fill_keys($stop, true);

        $tokens = [];
        foreach ($parts as $part) {
            $token = trim($part);
            if (mb_strlen($token) < 4 || isset($stopSet[$token])) {
                continue;
            }
            $tokens[] = $token;
        }

        return array_values(array_unique($tokens));
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

        foreach ($orderedCaseIds as $rankIndex => $caseId) {
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
                'source_id'       => $first->source_id ?? $caseId,
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
                'case_card'       => is_string($first->case_card ?? null)
                    ? json_decode($first->case_card, true)
                    : ($first->case_card ?? null),
                'full_text'       => $fullText,
                'excerpt'         => $excerpt,
                'chunk_count'     => $uniqueChunks->count(),
                'quality_flags'   => $flags,
                'match_sources'   => $score['match_sources'] ?? [],
                'relevance_score' => $score ? round($score['score'], 4) : null,
                'retrieval_rank'   => $rankIndex + 1,
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
