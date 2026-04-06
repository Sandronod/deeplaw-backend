<?php

namespace App\Services\Legal;

use App\DTOs\LawResult;
use App\Jobs\FetchMatsneLawJob;
use App\Services\Matsne\FetchLockService;
use App\Services\Matsne\MatsneFetchService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LawRetrieverService
{
    private const VECTOR_THRESHOLDS = [0.60, 0.45, 0.35];
    private const CHUNK_LIMIT       = 40;
    private const CACHE_TTL         = 3600; // 1 hour hot cache

    public function __construct(
        private readonly MatsneFetchService $matsne,
        private readonly FetchLockService   $fetchLock,
    ) {}

    /**
     * Deterministic-first law retrieval.
     *
     * Priority:
     *   1. Exact law title match   → all articles of that law
     *   2. Article number match    → filtered by law title when present
     *   3. Keyword search (ILIKE)  → multi-term AND logic
     *   4. Vector embedding        → semantic fallback only
     *
     * Results are hot-cached for 1 hour. Empty results are never cached.
     *
     * @param  array  $embedding  3072-dim vector
     * @param  string $rawQuery   Original user query
     * @param  int    $limit      Max results
     * @return LawResult[]
     */
    public function retrieve(array $embedding, string $rawQuery, int $limit = 5): array
    {
        $indexVersion = Cache::get(FetchMatsneLawJob::CACHE_INDEX_VERSION_KEY, 0);
        $cacheKey     = 'law_retrieval:v' . $indexVersion . ':' . md5($rawQuery . $limit);

        $cached = Cache::get($cacheKey);
        if ($cached !== null && !empty($cached)) {
            return $cached;
        }

        $results = $this->doRetrieve($embedding, $rawQuery, $limit);

        if (!empty($results)) {
            Cache::put($cacheKey, $results, self::CACHE_TTL);
        }

        return $results;
    }

    private function doRetrieve(array $embedding, string $rawQuery, int $limit): array
    {
        // ── 1. Exact law title match ──────────────────────────────────────────
        $results = $this->searchByExactTitle($rawQuery, $limit);
        if (!empty($results)) {
            Log::debug('LawRetrieverService: hit via exact title', ['query' => $rawQuery]);
            return $results;
        }

        // ── 2. Article number match ───────────────────────────────────────────
        $results = $this->searchByArticleNumber($rawQuery, $limit);
        if (!empty($results)) {
            Log::debug('LawRetrieverService: hit via article number', ['query' => $rawQuery]);
            return $results;
        }

        // ── 3. Keyword search (multi-term AND) ────────────────────────────────
        $results = $this->searchByKeyword($rawQuery, $limit);
        if (!empty($results)) {
            Log::debug('LawRetrieverService: hit via keyword', ['query' => $rawQuery]);
            return $results;
        }

        // ── 4. Vector embedding (semantic fallback) ───────────────────────────
        $results = $this->searchByVector($embedding, $limit);
        if (!empty($results)) {
            Log::debug('LawRetrieverService: hit via vector', ['query' => $rawQuery]);
            return $results;
        }

        // ── Miss → wait if fetch in progress, otherwise dispatch ─────────────
        Log::debug('LawRetrieverService: no results', ['query' => $rawQuery]);
        $matsneId = $this->matsne->resolveId($rawQuery);

        if ($matsneId && ($this->fetchLock->isLocked($matsneId) || $this->fetchLock->isQueued($matsneId))) {
            // Fetch already running — wait and retry once
            $released = $this->fetchLock->waitUntilReleased($matsneId);
            if ($released) {
                $retried = $this->doRetrieve($embedding, $rawQuery, $limit);
                if (!empty($retried)) {
                    return $retried;
                }
            }
            return [];
        }

        $this->triggerOnDemandFetch($rawQuery, $matsneId);
        return [];
    }

    // ── Search strategies ─────────────────────────────────────────────────────

    /**
     * Strict title matching:
     *   1. Normalized exact match (ყველაზე მკაცრი)
     *   2. Query contains full law title (substring)
     *   3. Law title contains full query (fallback for short queries)
     */
    private function searchByExactTitle(string $query, int $limit): array
    {
        $normQuery = $this->normalize($query);
        if (mb_strlen($normQuery) < 4) {
            return [];
        }

        // Load active laws and test against normalized query
        $laws = DB::connection('pgvector')
            ->table('laws')
            ->where('status', 'active')
            ->get(['id', 'title', 'source_url']);

        $matchedLawIds = [];
        foreach ($laws as $law) {
            $normTitle = $this->normalize($law->title);

            // Priority 1: normalized exact
            if ($normTitle === $normQuery) {
                $matchedLawIds[] = $law->id;
                continue;
            }

            // Priority 2: query contains full law title (must be at least 5 chars)
            if (mb_strlen($normTitle) >= 5 && str_contains($normQuery, $normTitle)) {
                $matchedLawIds[] = $law->id;
                continue;
            }

            // Priority 3: law title contains query (only when query is specific enough)
            if (mb_strlen($normQuery) >= 8 && str_contains($normTitle, $normQuery)) {
                $matchedLawIds[] = $law->id;
            }
        }

        if (empty($matchedLawIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($matchedLawIds), '?'));

        $rows = DB::connection('pgvector')->select("
            SELECT
                la.id            AS article_id,
                la.law_id,
                la.article_num,
                la.article_title,
                la.content,
                la.chunk_index,
                1.0              AS similarity,
                l.title          AS law_title,
                l.source_url
            FROM   law_articles la
            JOIN   laws l ON l.id = la.law_id
            WHERE  la.law_id IN ({$placeholders})
            ORDER  BY la.law_id ASC, la.chunk_index ASC
            LIMIT  ?
        ", [...$matchedLawIds, $limit * 3]);

        return $this->toResults($rows, $limit);
    }

    /**
     * Article number search — extracts article number and optionally filters by law title.
     *
     * Examples:
     *   "შრომის კოდექსის 37-ე მუხლი" → article_num LIKE '%მუხლი 37%' + law title contains "შრომის"
     *   "მუხლი 299"                   → article_num LIKE '%მუხლი 299%' (no law filter)
     */
    private function searchByArticleNumber(string $query, int $limit): array
    {
        $articleNum = null;

        if (preg_match('/მუხლი\s+([\d]+)/u', $query, $m)) {
            $articleNum = 'მუხლი ' . $m[1];
        } elseif (preg_match('/([\d]+)[–\-]?[ეა]?\s*მუხლი/u', $query, $m)) {
            $articleNum = 'მუხლი ' . $m[1];
        }

        if (!$articleNum) {
            return [];
        }

        // Try to narrow by law title mentioned in query
        $lawFilter = $this->extractLawTitleFilter($query);

        if ($lawFilter !== null) {
            $rows = DB::connection('pgvector')->select("
                SELECT
                    la.id            AS article_id,
                    la.law_id,
                    la.article_num,
                    la.article_title,
                    la.content,
                    la.chunk_index,
                    0.95             AS similarity,
                    l.title          AS law_title,
                    l.source_url
                FROM   law_articles la
                JOIN   laws l ON l.id = la.law_id
                WHERE  l.status = 'active'
                  AND  la.article_num ILIKE :num
                  AND  lower(l.title) ILIKE :law
                ORDER  BY la.chunk_index ASC
                LIMIT  :limit
            ", [
                'num'   => '%' . $articleNum . '%',
                'law'   => '%' . $lawFilter . '%',
                'limit' => $limit,
            ]);

            if (!empty($rows)) {
                return $this->toResults($rows, $limit);
            }
            // Fall through to unfiltered article search
        }

        $rows = DB::connection('pgvector')->select("
            SELECT
                la.id            AS article_id,
                la.law_id,
                la.article_num,
                la.article_title,
                la.content,
                la.chunk_index,
                0.95             AS similarity,
                l.title          AS law_title,
                l.source_url
            FROM   law_articles la
            JOIN   laws l ON l.id = la.law_id
            WHERE  l.status = 'active'
              AND  la.article_num ILIKE :num
            ORDER  BY la.chunk_index ASC
            LIMIT  :limit
        ", [
            'num'   => '%' . $articleNum . '%',
            'limit' => $limit,
        ]);

        return $this->toResults($rows, $limit);
    }

    /**
     * Multi-term keyword search — all terms must match (AND logic).
     * Each term is searched across content and article_title.
     */
    private function searchByKeyword(string $query, int $limit): array
    {
        $terms = $this->extractSearchTerms($query);
        if (empty($terms)) {
            return [];
        }

        // Build AND conditions: each term must appear in content OR article_title
        $conditions = [];
        $bindings   = [];

        foreach ($terms as $i => $term) {
            $conditions[] = "(la.content ILIKE :t{$i}c OR la.article_title ILIKE :t{$i}a)";
            $bindings["t{$i}c"] = '%' . $term . '%';
            $bindings["t{$i}a"] = '%' . $term . '%';
        }

        $where     = implode(' AND ', $conditions);
        $bindings['limit'] = $limit * 2;

        $rows = DB::connection('pgvector')->select("
            SELECT
                la.id            AS article_id,
                la.law_id,
                la.article_num,
                la.article_title,
                la.content,
                la.chunk_index,
                0.75             AS similarity,
                l.title          AS law_title,
                l.source_url
            FROM   law_articles la
            JOIN   laws l ON l.id = la.law_id
            WHERE  l.status = 'active'
              AND  {$where}
            ORDER  BY
                CASE WHEN la.article_title ILIKE :sort_term THEN 0 ELSE 1 END,
                la.chunk_index ASC
            LIMIT  :limit
        ", array_merge($bindings, ['sort_term' => '%' . $terms[0] . '%']));

        // If AND gives no results, retry with first term only
        if (empty($rows) && count($terms) > 1) {
            $rows = DB::connection('pgvector')->select("
                SELECT
                    la.id            AS article_id,
                    la.law_id,
                    la.article_num,
                    la.article_title,
                    la.content,
                    la.chunk_index,
                    0.65             AS similarity,
                    l.title          AS law_title,
                    l.source_url
                FROM   law_articles la
                JOIN   laws l ON l.id = la.law_id
                WHERE  l.status = 'active'
                  AND  (la.content ILIKE :q OR la.article_title ILIKE :q2)
                ORDER  BY la.chunk_index ASC
                LIMIT  :limit
            ", [
                'q'     => '%' . $terms[0] . '%',
                'q2'    => '%' . $terms[0] . '%',
                'limit' => $limit * 2,
            ]);
        }

        return $this->toResults($rows, $limit);
    }

    private function searchByVector(array $embedding, int $limit): array
    {
        if (empty($embedding)) {
            return [];
        }

        $vec  = '[' . implode(',', $embedding) . ']';
        $rows = [];

        foreach (self::VECTOR_THRESHOLDS as $threshold) {
            $rows = DB::connection('pgvector')->select("
                SELECT
                    la.id            AS article_id,
                    la.law_id,
                    la.article_num,
                    la.article_title,
                    la.content,
                    la.chunk_index,
                    1 - (la.embedding <=> :emb::vector) AS similarity,
                    l.title          AS law_title,
                    l.source_url
                FROM   law_articles la
                JOIN   laws l ON l.id = la.law_id
                WHERE  l.status = 'active'
                  AND  la.embedding IS NOT NULL
                  AND  1 - (la.embedding <=> :emb2::vector) >= :threshold
                ORDER  BY similarity DESC
                LIMIT  :chunk_limit
            ", [
                'emb'         => $vec,
                'emb2'        => $vec,
                'threshold'   => $threshold,
                'chunk_limit' => self::CHUNK_LIMIT,
            ]);

            if (!empty($rows)) {
                break;
            }
        }

        return $this->toResults($rows, $limit);
    }

    // ── Result building ───────────────────────────────────────────────────────

    /**
     * Deduplicate by law, keep best-scoring article per law, return top $limit.
     */
    private function toResults(array $rows, int $limit): array
    {
        if (empty($rows)) {
            return [];
        }

        $byLaw = [];
        foreach ($rows as $row) {
            $lid = $row->law_id;
            if (!isset($byLaw[$lid]) || (float) $row->similarity > (float) $byLaw[$lid]->similarity) {
                $byLaw[$lid] = $row;
            }
        }

        usort($byLaw, fn($a, $b) => (float) $b->similarity <=> (float) $a->similarity);

        return array_map(fn($row) => new LawResult(
            lawId:        $row->law_id,
            articleId:    $row->article_id,
            title:        $row->law_title,
            articleNum:   $row->article_num   ?? '',
            articleTitle: $row->article_title ?? '',
            excerpt:      mb_substr($row->content, 0, 800),
            similarity:   round((float) $row->similarity, 4),
            sourceUrl:    $row->source_url ?? '',
        ), array_slice($byLaw, 0, $limit));
    }

    // ── On-demand fetch ───────────────────────────────────────────────────────

    private function triggerOnDemandFetch(string $rawQuery, ?int $matsneId = null): void
    {
        $matsneId ??= $this->matsne->resolveId($rawQuery);
        if (!$matsneId) {
            return;
        }

        $exists = DB::connection('pgvector')
            ->table('laws')
            ->where('matsne_id', (string) $matsneId)
            ->exists();
        if ($exists) {
            return;
        }

        if ($this->fetchLock->isQueued($matsneId) || $this->fetchLock->isLocked($matsneId)) {
            Log::debug('LawRetrieverService: fetch already in progress', ['matsne_id' => $matsneId]);
            return;
        }

        $this->fetchLock->markQueued($matsneId);
        FetchMatsneLawJob::dispatch($matsneId, $rawQuery)->onQueue('matsne-fetch');

        Log::info('LawRetrieverService: fetch dispatched', [
            'matsne_id' => $matsneId,
            'query'     => $rawQuery,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Normalize a string for comparison:
     * lowercase, strip punctuation/dashes, collapse whitespace.
     */
    private function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[\-–—\/\\\\.,:;!?«»""\'`()\[\]{}]/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }

    /**
     * Extract a law title hint from the query (for article number filtering).
     * Returns normalized lowercase snippet or null.
     */
    private function extractLawTitleFilter(string $query): ?string
    {
        $lower = mb_strtolower($query);

        // Known law keywords to anchor on
        $anchors = ['კოდექსი', 'კანონი', 'კოდექსის', 'კანონის'];

        foreach ($anchors as $anchor) {
            $pos = mb_strpos($lower, $anchor);
            if ($pos !== false) {
                // Take up to 3 words before anchor + anchor itself
                $before  = mb_substr($lower, max(0, $pos - 30), 30);
                $words   = preg_split('/\s+/u', trim($before));
                $snippet = implode(' ', array_slice($words, -2)) . ' ' . $anchor;
                return trim($snippet);
            }
        }

        return null;
    }

    /**
     * Extract meaningful search terms (array), strip stop words.
     * Returns up to 3 terms for AND-based keyword search.
     *
     * @return string[]
     */
    private function extractSearchTerms(string $query): array
    {
        $stopWords = [
            'რა', 'რომ', 'და', 'ან', 'მე', 'შენ', 'ის', 'ჩვენ', 'თქვენ', 'ისინი',
            'ამბობს', 'ამბობ', 'ვიცი', 'მინდა', 'მომეცი', 'ახსენი', 'გამიხსენი',
            'შესახებ', 'ზედ', 'ზე', 'ში', 'ით', 'ად', 'თვის', 'გამო', 'რომელი',
            'მუხლი', 'მუხლის', 'მუხლში', 'პუნქტი', 'ნაწილი',
        ];

        $words = preg_split('/\s+/u', mb_strtolower(trim($query)));

        $meaningful = array_values(array_filter(
            $words,
            fn($w) => mb_strlen($w) >= 3 && !in_array($w, $stopWords)
        ));

        return array_slice($meaningful, 0, 3);
    }
}
