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
        array $embedding,
        int   $limit    = 20,
        float $minScore = 0.65,
        ?int  $year     = null,
    ): \Illuminate\Support\Collection {
        $vector = self::formatVector($embedding);
        $yearFilter = $year
            ? "AND EXTRACT(YEAR FROM cm.case_date) = {$year}"
            : '';

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
                cc.content,
                cc.chunk_index,
                1 - (cc.embedding <=> '{$vector}'::vector) AS similarity
            FROM court_chunks cc
            JOIN court_cases  cm ON cm.id = cc.case_id
            WHERE 1 - (cc.embedding <=> '{$vector}'::vector) >= ?
            {$yearFilter}
            ORDER BY cc.embedding <=> '{$vector}'::vector
            LIMIT ?
        SQL;

        return collect(\Illuminate\Support\Facades\DB::connection('pgvector')->select($sql, [$minScore, $limit]));
    }

    // ── Metadata search ───────────────────────────────────────────────────────

    /**
     * ILIKE search across case metadata and chunk content.
     * Returns one row per case (DISTINCT ON case_id).
     */
    public static function metadataSearch(
        string $query,
        int    $limit = 30,
        ?int   $year  = null,
    ): \Illuminate\Support\Collection {
        $term = '%' . trim($query) . '%';
        $yearFilter = $year
            ? "AND EXTRACT(YEAR FROM cm.case_date) = {$year}"
            : '';

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
                cc.content,
                cc.chunk_index
            FROM court_cases  cm
            JOIN court_chunks cc ON cc.case_id = cm.id
            WHERE (
                cm.case_num         ILIKE ?
                OR cm.dispute_subject ILIKE ?
                OR cm.category        ILIKE ?
                OR cm.result          ILIKE ?
                OR cm.claim_type      ILIKE ?
                OR cm.kind            ILIKE ?
                OR cm.chamber         ILIKE ?
                OR cm.court           ILIKE ?
                OR cc.content         ILIKE ?
            )
            {$yearFilter}
            ORDER BY cm.id, cc.id
            LIMIT ?
        SQL;

        return collect(\Illuminate\Support\Facades\DB::connection('pgvector')->select($sql, [
            $term, $term, $term, $term, $term,
            $term, $term, $term, $term,
            $limit,
        ]));
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
                cm.case_type
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
}
