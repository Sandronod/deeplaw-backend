<?php

namespace App\Services\Echr;

use App\Models\EchrCase;
use App\Models\EchrCaseArticle;
use App\Models\EchrParagraph;
use App\Models\EchrSyncLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Manages local persistence of ECHR cases, articles, paragraphs, and sync logs.
 */
class EchrRepositoryService
{
    /**
     * Insert or update an ECHR case from parsed HUDOC data.
     * Creates/replaces article and paragraph records atomically.
     *
     * @param  array $parsed  Output of HudocParserService::parse()
     * @return array{case: EchrCase, is_new: bool}
     */
    public function upsert(array $parsed): array
    {
        $isNew = false;

        DB::connection('pgvector')->transaction(function () use ($parsed, &$echrCase, &$isNew) {
            $existing = EchrCase::where('hudoc_itemid', $parsed['hudoc_itemid'])->first();
            $isNew    = $existing === null;

            $caseData = collect($parsed)
                ->except(['articles', 'chunks'])
                ->merge(['last_synced_at' => now()])
                ->toArray();

            if ($existing) {
                $existing->update($caseData);
                $echrCase = $existing->fresh();
            } else {
                $echrCase = EchrCase::create($caseData);
            }

            // Replace articles
            EchrCaseArticle::where('echr_case_id', $echrCase->id)->delete();
            foreach ($parsed['articles'] as $art) {
                EchrCaseArticle::create([
                    'echr_case_id'  => $echrCase->id,
                    'article_code'  => $art['code'],
                    'article_label' => $art['label'],
                ]);
            }

            // Replace paragraphs (chunks) — only update if new or text has changed
            if (!empty($parsed['chunks'])) {
                EchrParagraph::where('echr_case_id', $echrCase->id)->delete();
                foreach ($parsed['chunks'] as $chunk) {
                    EchrParagraph::create([
                        'echr_case_id' => $echrCase->id,
                        'chunk_index'  => $chunk['chunk_index'],
                        'content'      => $chunk['content'],
                        'section_type' => $chunk['section_type'] ?? 'body',
                        // embedding will be set separately by the job
                    ]);
                }
            }
        });

        return ['case' => $echrCase, 'is_new' => $isNew];
    }

    /**
     * Attach embedding to a paragraph chunk.
     * Uses raw SQL with ::vector cast — same pattern as FetchMatsneLawJob.
     */
    public function embedParagraph(int $paragraphId, array $embedding): void
    {
        $vec = '[' . implode(',', $embedding) . ']';

        DB::connection('pgvector')->statement(
            'UPDATE echr_paragraphs SET embedding = :emb::vector WHERE id = :id',
            ['emb' => $vec, 'id' => $paragraphId]
        );
    }

    // ── Local search methods ───────────────────────────────────────────────────

    public function findByItemId(string $itemId): ?EchrCase
    {
        return EchrCase::with(['articles'])
            ->where('hudoc_itemid', $itemId)
            ->first();
    }

    public function findByApplicationNumber(string $appNo): ?EchrCase
    {
        $normalized = trim($appNo);
        return EchrCase::with(['articles'])
            ->where('application_number', $normalized)
            ->first();
    }

    /**
     * Keyword search across title and summary via trigram ILIKE.
     */
    public function searchByKeyword(string $keyword, int $limit = 8): Collection
    {
        $like = '%' . addcslashes($keyword, '%_\\') . '%';

        return EchrCase::with(['articles'])
            ->where('status', 'active')
            ->where(function ($q) use ($like) {
                $q->whereRaw('lower(title) ILIKE ?', [strtolower($like)])
                  ->orWhereRaw('lower(summary) ILIKE ?', [strtolower($like)]);
            })
            ->orderByRaw('CASE WHEN importance = 1 THEN 0 WHEN importance = 2 THEN 1 ELSE 2 END')
            ->orderByDesc('judgment_date')
            ->limit($limit)
            ->get();
    }

    /**
     * Search by Convention article code.
     */
    public function searchByArticle(string $articleCode, int $limit = 8): Collection
    {
        return EchrCase::with(['articles'])
            ->where('status', 'active')
            ->whereHas('articles', fn($q) => $q->where('article_code', $articleCode))
            ->orderByRaw('CASE WHEN importance = 1 THEN 0 WHEN importance = 2 THEN 1 ELSE 2 END')
            ->orderByDesc('judgment_date')
            ->limit($limit)
            ->get();
    }

    /**
     * Vector similarity search over echr_paragraphs.
     * Groups results by case, returns best matching case per embedding.
     *
     * @param  float[] $embedding
     * @return Collection<EchrCase>  with attached best_similarity and best_excerpt
     */
    public function vectorSearch(array $embedding, int $limit = 8, float $threshold = 0.65): Collection
    {
        $vectorStr = '[' . implode(',', $embedding) . ']';
        $cosineMax = 1 - $threshold; // pgvector uses distance, not similarity

        $rows = DB::connection('pgvector')->select(<<<SQL
            SELECT
                ep.echr_case_id,
                MIN(ep.embedding <=> ?::vector) AS best_distance,
                AVG(ep.embedding <=> ?::vector) AS avg_distance,
                (array_agg(ep.content ORDER BY ep.embedding <=> ?::vector ASC))[1] AS best_chunk
            FROM echr_paragraphs ep
            WHERE ep.embedding IS NOT NULL
              AND (ep.embedding <=> ?::vector) <= ?
            GROUP BY ep.echr_case_id
            ORDER BY best_distance ASC
            LIMIT ?
            SQL,
            [$vectorStr, $vectorStr, $vectorStr, $vectorStr, $cosineMax, $limit]
        );

        if (empty($rows)) {
            return collect();
        }

        $caseIds      = array_column($rows, 'echr_case_id');
        $distanceMap  = array_column($rows, 'best_distance', 'echr_case_id');
        $chunkMap     = array_column($rows, 'best_chunk', 'echr_case_id');

        $cases = EchrCase::with(['articles'])
            ->whereIn('id', $caseIds)
            ->where('status', 'active')
            ->get()
            ->keyBy('id');

        return collect($caseIds)
            ->filter(fn($id) => $cases->has($id))
            ->map(function ($id) use ($cases, $distanceMap, $chunkMap) {
                $case = $cases[$id];
                $case->best_similarity = round(1 - (float) $distanceMap[$id], 4);
                $case->best_chunk      = $chunkMap[$id];
                return $case;
            });
    }

    // ── Sync logging ──────────────────────────────────────────────────────────

    public function log(string $queryType, string $queryValue, array $stats, string $status = 'success', ?string $error = null): void
    {
        try {
            EchrSyncLog::create([
                'query_type'    => $queryType,
                'query_value'   => $queryValue,
                'cases_fetched' => $stats['fetched'] ?? 0,
                'cases_new'     => $stats['new']     ?? 0,
                'status'        => $status,
                'error_message' => $error,
                'details'       => $stats['details'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error('EchrRepositoryService: failed to write sync log', ['error' => $e->getMessage()]);
        }
    }
}
