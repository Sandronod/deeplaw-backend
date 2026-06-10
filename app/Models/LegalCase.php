<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * LegalCase — query facade over the normalized court_cases + court_chunks tables.
 *
 * Schema:
 *   court_cases  — one row per case (metadata: case_num, court, chamber, …)
 *   court_chunks — many rows per case (content + 1024-dim embedding)
 */
class LegalCase extends Model
{
    // Eloquent table — used only for chunksForCases() Eloquent fallback.
    // All search methods use raw SQL with JOINs.
    protected $table = 'court_chunks';

    protected $connection = 'pgvector';

    public $timestamps = false;

    // ── Vector search ─────────────────────────────────────────────────────────

    /**
     * Cosine similarity search over court_chunks, returning one row per chunk
     * enriched with court_cases metadata.
     *
     * @param  array  $embedding  1024-dim float array
     * @param  int    $limit
     * @param  float  $minScore   Cosine similarity threshold (0–1)
     * @param  int|null $year     Filter by case year
     */
    public static function vectorSearch(
        array   $embedding,
        int     $limit    = 20,
        float   $minScore = 0.65,
        ?int    $year     = null,
        ?string $caseType = null,
    ): \Illuminate\Support\Collection {
        self::setIvfflatProbes();

        $vector     = self::formatVector($embedding);
        $yearFilter = $year     ? "AND EXTRACT(YEAR FROM cm.case_date) = {$year}" : '';
        $typeFilter = $caseType ? 'AND cm.case_type = ?'                           : '';

        $sql = <<<SQL
            SELECT
                cc.id,
                cm.id              AS case_id,
                cm.source_id,
                cm.case_num,
                cm.dispute_subject,
                cm.case_date,
                cm.category,
                cm.result,
                cm.claim_type,
                cm.kind,
                cm.chamber,
                cm.court,
                cm.case_type,
                cm.case_card,
                cc.content,
                cc.chunk_index,
                1 - (cc.embedding <=> '{$vector}'::vector) AS similarity,
                'chunk_vector' AS match_source
            FROM court_chunks cc
            JOIN court_cases  cm ON cm.id = cc.case_id
            WHERE 1 - (cc.embedding <=> '{$vector}'::vector) >= ?
            {$yearFilter}
            {$typeFilter}
            ORDER BY cc.embedding <=> '{$vector}'::vector
            LIMIT ?
        SQL;

        $params = [$minScore];
        if ($caseType) {
            $params[] = $caseType;
        }
        $params[] = $limit;

        return collect(\Illuminate\Support\Facades\DB::connection('pgvector')->select($sql, $params));
    }

    /**
     * Exact-ish fallback for long pasted decision text.
     *
     * Vector search can miss near-duplicate chunks with ivfflat approximation,
     * and semantic search is the wrong tool when the user pastes text that
     * already exists verbatim in a decision. This uses FTS windows plus a few
     * high-signal literal anchors (document numbers, distinctive lines).
     */
    public static function pastedTextSearch(
        string  $text,
        int     $limit    = 10,
        ?string $caseType = null,
    ): \Illuminate\Support\Collection {
        $windows = self::pastedTextWindows($text);
        $needles = self::pastedTextNeedles($text);

        if (empty($windows) && empty($needles)) {
            return collect();
        }

        $vectorExpr = "to_tsvector('simple', coalesce(cc.content, ''))";
        $scoreParts = [];
        $whereParts = [];
        $scoreParams = [];
        $whereParams = [];

        foreach ($windows as $window) {
            $scoreParts[] = "CASE WHEN {$vectorExpr} @@ plainto_tsquery('simple', ?) THEN ts_rank_cd({$vectorExpr}, plainto_tsquery('simple', ?)) ELSE 0 END";
            $scoreParams[] = $window;
            $scoreParams[] = $window;

            $whereParts[] = "{$vectorExpr} @@ plainto_tsquery('simple', ?)";
            $whereParams[] = $window;
        }

        foreach ($needles as $needle) {
            $scoreParts[] = 'CASE WHEN cc.content ILIKE ? THEN 4.0 ELSE 0 END';
            $scoreParams[] = '%' . $needle . '%';

            $whereParts[] = 'cc.content ILIKE ?';
            $whereParams[] = '%' . $needle . '%';
        }

        $scoreSql = implode(' + ', $scoreParts);
        $whereSql = implode("\n                OR ", $whereParts);
        $typeFilter = $caseType ? 'AND cm.case_type = ?' : '';

        $sql = <<<SQL
            WITH scored AS (
                SELECT
                    cc.id,
                    cm.id              AS case_id,
                    cm.source_id,
                    cm.case_num,
                    cm.dispute_subject,
                    cm.case_date,
                    cm.category,
                    cm.result,
                    cm.claim_type,
                    cm.kind,
                    cm.chamber,
                    cm.court,
                    cm.case_type,
                    cm.case_card,
                    cc.content,
                    cc.chunk_index,
                    ({$scoreSql}) AS text_rank
                FROM court_chunks cc
                JOIN court_cases  cm ON cm.id = cc.case_id
                WHERE (
                    {$whereSql}
                )
                {$typeFilter}
            ),
            best_per_case AS (
                SELECT DISTINCT ON (case_id) *
                FROM scored
                ORDER BY case_id, text_rank DESC, id ASC
            )
            SELECT *
                , LEAST(1.0, 0.58 + LN(1.0 + GREATEST(text_rank, 0.0)) / 2.5) AS similarity
                , 'pasted_text' AS match_source
            FROM best_per_case
            ORDER BY text_rank DESC, case_date DESC NULLS LAST
            LIMIT ?
        SQL;

        $params = array_merge($scoreParams, $whereParams);
        if ($caseType) {
            $params[] = $caseType;
        }
        $params[] = $limit;

        return collect(\Illuminate\Support\Facades\DB::connection('pgvector')->select($sql, $params));
    }

    // ── Metadata search ───────────────────────────────────────────────────────

    /**
     * ILIKE search across case metadata and chunk content.
     * Returns one row per case (DISTINCT ON case_id).
     */
    /**
     * @param  bool $includeContent  When true, also searches chunk text (needed for judge name lookups).
     *                               Default false to avoid false positives from general keyword searches.
     */
    public static function metadataSearch(
        string  $query,
        int     $limit          = 30,
        ?int    $year           = null,
        ?string $caseType       = null,
        bool    $includeContent = false,
    ): \Illuminate\Support\Collection {
        $term       = '%' . trim($query) . '%';
        $yearFilter = $year     ? "AND EXTRACT(YEAR FROM cm.case_date) = {$year}" : '';
        $typeFilter = $caseType ? 'AND cm.case_type = ?'                           : '';
        $contentClause = $includeContent ? 'OR cc.content ILIKE ?' : '';

        $sql = <<<SQL
            SELECT DISTINCT ON (cm.id)
                cc.id,
                cm.id              AS case_id,
                cm.source_id,
                cm.case_num,
                cm.dispute_subject,
                cm.case_date,
                cm.category,
                cm.result,
                cm.claim_type,
                cm.kind,
                cm.chamber,
                cm.court,
                cm.case_type,
                cm.case_card,
                cc.content,
                cc.chunk_index,
                'metadata' AS match_source
            FROM court_cases  cm
            JOIN court_chunks cc ON cc.case_id = cm.id
            WHERE (
                cm.case_num           ILIKE ?
                OR cm.dispute_subject ILIKE ?
                OR cm.category        ILIKE ?
                OR cm.result          ILIKE ?
                OR cm.claim_type      ILIKE ?
                OR cm.kind            ILIKE ?
                OR cm.chamber         ILIKE ?
                OR cm.court           ILIKE ?
                {$contentClause}
            )
            {$yearFilter}
            {$typeFilter}
            ORDER BY cm.id, cc.id
            LIMIT ?
        SQL;

        $params = [$term, $term, $term, $term, $term, $term, $term, $term];
        if ($includeContent) {
            $params[] = $term;
        }
        if ($caseType) {
            $params[] = $caseType;
        }
        $params[] = $limit;

        return collect(\Illuminate\Support\Facades\DB::connection('pgvector')->select($sql, $params));
    }

    // ── Case-level embedding search ──────────────────────────────────────────

    /**
     * Cosine similarity search over court_cases.case_embedding (concept-level).
     *
     * Returns one row per case, enriched with the first chunk's content,
     * in the same shape as vectorSearch() so results plug into the merge pipeline.
     *
     * @param  array  $embedding  1024-dim bge-m3 float array
     * @param  int    $limit
     * @param  float  $minScore   Similarity threshold (lower than chunk-level: 0.45)
     */
    public static function caseEmbeddingSearch(
        array   $embedding,
        int     $limit    = 10,
        float   $minScore = 0.45,
        ?string $caseType = null,
    ): \Illuminate\Support\Collection {
        self::setIvfflatProbes();

        $vector     = self::formatVector($embedding);
        $typeFilter = $caseType ? 'AND cm.case_type = ?' : '';

        $sql = <<<SQL
            SELECT
                ck.id,
                cm.id              AS case_id,
                cm.source_id,
                cm.case_num,
                cm.dispute_subject,
                cm.case_date,
                cm.category,
                cm.result,
                cm.claim_type,
                cm.kind,
                cm.chamber,
                cm.court,
                cm.case_type,
                cm.case_card,
                ck.content,
                ck.chunk_index,
                1 - (cm.case_embedding <=> '{$vector}'::vector) AS similarity,
                'case_embedding' AS match_source
            FROM court_cases cm
            JOIN LATERAL (
                SELECT id, content, chunk_index
                FROM court_chunks
                WHERE case_id = cm.id
                ORDER BY chunk_index ASC NULLS LAST
                LIMIT 1
            ) ck ON true
            WHERE cm.case_embedding IS NOT NULL
              AND 1 - (cm.case_embedding <=> '{$vector}'::vector) >= ?
              {$typeFilter}
            ORDER BY cm.case_embedding <=> '{$vector}'::vector
            LIMIT ?
        SQL;

        $params = [$minScore];
        if ($caseType) {
            $params[] = $caseType;
        }
        $params[] = $limit;

        return collect(\Illuminate\Support\Facades\DB::connection('pgvector')->select($sql, $params));
    }

    /**
     * Exact-ish search over court_cases.case_card.
     *
     * This rescues cases whose structured legal_issue/holding matches the query
     * but whose embeddings rank below the vector candidate cut. It is intentionally
     * strict: at least 70% of meaningful query tokens must appear in the case card.
     */
    public static function caseCardKeywordSearch(
        string  $query,
        int     $limit    = 30,
        ?string $caseType = null,
    ): \Illuminate\Support\Collection {
        $tokens = self::caseCardSearchTokens($query);

        if (count($tokens) < 3) {
            return collect();
        }

        $tokenCount = count($tokens);
        $minMatches = max(3, (int) ceil($tokenCount * 0.70));
        $typeFilter = $caseType ? 'AND cm.case_type = ?' : '';

        $scoreParts = [];
        foreach ($tokens as $i => $_token) {
            $scoreParts[] = "CASE WHEN LOWER(cm.case_card::text) LIKE ? THEN 1 ELSE 0 END";
        }

        $scoreExpr = implode(' + ', $scoreParts);

        $sql = <<<SQL
            SELECT
                ck.id,
                cm.id              AS case_id,
                cm.source_id,
                cm.case_num,
                cm.dispute_subject,
                cm.case_date,
                cm.category,
                cm.result,
                cm.claim_type,
                cm.kind,
                cm.chamber,
                cm.court,
                cm.case_type,
                cm.case_card,
                ck.content,
                ck.chunk_index,
                LEAST(0.99, 0.68 + 0.31 * (({$scoreExpr})::float / {$tokenCount})) AS similarity,
                'case_card_keyword' AS match_source
            FROM court_cases cm
            JOIN LATERAL (
                SELECT id, content, chunk_index
                FROM court_chunks
                WHERE case_id = cm.id
                ORDER BY chunk_index ASC NULLS LAST, id ASC
                LIMIT 1
            ) ck ON true
            WHERE cm.case_card IS NOT NULL
              AND ({$scoreExpr}) >= {$minMatches}
              {$typeFilter}
            ORDER BY (({$scoreExpr})::float / {$tokenCount}) DESC,
                     cm.case_date DESC NULLS LAST,
                     cm.id DESC
            LIMIT ?
        SQL;

        $likes = array_map(fn (string $token) => '%' . $token . '%', $tokens);
        $params = [
            ...$likes, // SELECT score expression
            ...$likes, // WHERE score expression
        ];

        if ($caseType) {
            $params[] = $caseType;
        }

        $params = [
            ...$params,
            ...$likes, // ORDER BY score expression
            $limit,
        ];

        return collect(\Illuminate\Support\Facades\DB::connection('pgvector')->select($sql, $params));
    }

    /**
     * @return array<int, string>
     */
    private static function caseCardSearchTokens(string $query): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [];
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

        return array_slice(array_values(array_unique($tokens)), 0, 10);
    }

    // ── Chunk reconstruction ──────────────────────────────────────────────────

    /**
     * Fetch all chunks for given case_ids, ordered by chunk_index then id.
     * Enriched with court_cases metadata via JOIN.
     */
    public static function chunksForCases(array $caseIds): \Illuminate\Support\Collection
    {
        if (empty($caseIds)) {
            return collect();
        }

        $placeholders = implode(',', array_fill(0, count($caseIds), '?'));

        $sql = <<<SQL
            SELECT
                cc.id,
                cc.case_id,
                cm.source_id,
                cc.chunk_index,
                cc.content,
                cm.case_num,
                cm.dispute_subject,
                cm.case_date,
                cm.category,
                cm.result,
                cm.claim_type,
                cm.kind,
                cm.chamber,
                cm.court,
                cm.case_type,
                cm.case_card
            FROM court_chunks cc
            JOIN court_cases  cm ON cm.id = cc.case_id
            WHERE cc.case_id IN ({$placeholders})
            ORDER BY cc.chunk_index ASC NULLS LAST, cc.id ASC
        SQL;

        return collect(\Illuminate\Support\Facades\DB::connection('pgvector')->select($sql, $caseIds));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public static function formatVector(array $embedding): string
    {
        return '[' . implode(',', array_map('floatval', $embedding)) . ']';
    }

    private static function setIvfflatProbes(int $probes = 20): void
    {
        try {
            \Illuminate\Support\Facades\DB::connection('pgvector')
                ->statement("SET ivfflat.probes = {$probes}");
        } catch (\Throwable) {
            // Non-fatal: exact/lexical fallbacks still run if this setting is unavailable.
        }
    }

    /**
     * @return array<int, string>
     */
    private static function pastedTextNeedles(string $text): array
    {
        $normalized = self::normalizePastedText($text);
        $needles = [];

        preg_match_all('/(?:\x{2116}\s*)?\d{1,4}\/\d{4,}(?:[-\/]\d+)?/u', $normalized, $docNums);
        foreach ($docNums[0] ?? [] as $docNum) {
            $docNum = trim($docNum);
            $needles[] = $docNum;
            $needles[] = trim(preg_replace('/^\x{2116}\s*/u', '', $docNum) ?? $docNum);
        }

        $lines = preg_split('/\R+/u', $text) ?: [];
        foreach ($lines as $line) {
            $line = self::normalizePastedText($line);
            $len = mb_strlen($line);
            if ($len < 35 || $len > 170) {
                continue;
            }
            if (count(self::meaningfulPastedTokens($line)) < 4) {
                continue;
            }
            $needles[] = $line;
            if (count($needles) >= 8) {
                break;
            }
        }

        return array_values(array_unique(array_slice($needles, 0, 8)));
    }

    /**
     * @return array<int, string>
     */
    private static function pastedTextWindows(string $text): array
    {
        $tokens = self::meaningfulPastedTokens($text);
        if (count($tokens) < 6) {
            return [];
        }

        $windowSize = 7;
        $maxStart = max(0, count($tokens) - $windowSize);
        $starts = array_values(array_unique(array_filter([
            0,
            min($maxStart, 8),
            min($maxStart, 18),
            (int) floor($maxStart * 0.45),
            (int) floor($maxStart * 0.7),
        ], fn (int $start) => $start >= 0)));

        $windows = [];
        foreach ($starts as $start) {
            $slice = array_slice($tokens, $start, $windowSize);
            if (count($slice) >= 5) {
                $windows[] = implode(' ', $slice);
            }
        }

        return array_values(array_unique($windows));
    }

    /**
     * @return array<int, string>
     */
    private static function meaningfulPastedTokens(string $text): array
    {
        $normalized = mb_strtolower(self::normalizePastedText($text));
        $parts = preg_split('/[^\p{L}\p{N}\/.-]+/u', $normalized) ?: [];

        $stop = [
            'და', 'ან', 'თუ', 'რომ', 'არის', 'იყო', 'იქნა', 'საქმე', 'საქმეზე', 'საკითხი',
            'შესახებ', 'შემდეგი', 'წლის', 'მიერ', 'ასევე', 'ყველა', 'რომელიც', 'ამ',
        ];
        $stopSet = array_fill_keys($stop, true);

        $tokens = [];
        foreach ($parts as $part) {
            $token = trim($part, " \t\n\r\0\x0B.,;:()[]{}\"'");
            if (mb_strlen($token) < 4 || isset($stopSet[$token])) {
                continue;
            }
            $tokens[] = $token;
        }

        return array_values($tokens);
    }

    private static function normalizePastedText(string $text): string
    {
        $text = str_replace("\u{00A0}", ' ', $text);
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
