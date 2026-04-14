<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class LegalCase extends Model
{
    protected $table = 'cases';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
        'case_id',
        'case_num',
        'dispute_subject',
        'case_date',
        'category',
        'result',
        'claim_type',
        'kind',
        'chamber',
        'court',
        'content',
        'embedding',
        'meta',
        'case_type',
    ];

    protected $casts = [
        'id'        => 'integer',
        'case_id'   => 'integer',
        'case_date' => 'date',
        'meta'      => 'array',
    ];

    /**
     * Vector similarity search — returns top matching chunks with similarity score.
     * Uses cosine distance (<=>). Lower distance = more similar.
     *
     * @param  array  $embedding  Float array from OpenAI
     * @param  int    $limit
     * @param  float  $minScore   Cosine similarity threshold (0–1)
     */
    public static function vectorSearch(array $embedding, int $limit = 20, float $minScore = 0.65, ?int $year = null): \Illuminate\Support\Collection
    {
        $vector = self::formatVector($embedding);

        $yearFilter = $year ? "AND EXTRACT(YEAR FROM case_date) = {$year}" : '';

        $sql = <<<SQL
            SELECT
                id, case_id, case_num, dispute_subject, case_date,
                category, result, claim_type, kind, chamber,
                court, content, meta, case_type,
                1 - (embedding <=> '{$vector}'::vector) AS similarity
            FROM cases
            WHERE 1 - (embedding <=> '{$vector}'::vector) >= ?
            {$yearFilter}
            ORDER BY embedding <=> '{$vector}'::vector
            LIMIT ?
        SQL;

        return collect(\Illuminate\Support\Facades\DB::select($sql, [$minScore, $limit]));
    }

    /**
     * Metadata search — ეძებს case_num, court, chamber, category, dispute_subject,
     * claim_type, kind, result და content სვეტებში ILIKE-ით.
     *
     * DISTINCT ON (case_id) — ერთი row per case, არა per chunk.
     * ეს კრიტიკულია: სახელის ძებნისას content-ში ბევრი chunk შეიძლება match-ოს,
     * მაგრამ ჩვენ case-ები გვინდა, არა ცალკეული chunk-ები.
     */
    public static function metadataSearch(string $query, int $limit = 30, ?int $year = null): \Illuminate\Support\Collection
    {
        $term = '%' . trim($query) . '%';

        $yearFilter = $year ? "AND EXTRACT(YEAR FROM case_date) = {$year}" : '';

        $sql = <<<SQL
            SELECT DISTINCT ON (case_id)
                id, case_id, case_num, dispute_subject, case_date,
                category, result, claim_type, kind, chamber,
                court, content, meta, case_type
            FROM cases
            WHERE (
                case_num        ILIKE ?
               OR dispute_subject ILIKE ?
               OR category        ILIKE ?
               OR result          ILIKE ?
               OR claim_type      ILIKE ?
               OR kind            ILIKE ?
               OR chamber         ILIKE ?
               OR court           ILIKE ?
               OR content         ILIKE ?
            )
            {$yearFilter}
            ORDER BY case_id, id
            LIMIT ?
        SQL;

        return collect(\Illuminate\Support\Facades\DB::select($sql, [
            $term, $term, $term, $term, $term,
            $term, $term, $term, $term,
            $limit,
        ]));
    }

    /**
     * Fetch all chunks for given case_ids, ordered correctly.
     */
    public static function chunksForCases(array $caseIds): \Illuminate\Support\Collection
    {
        return static::whereIn('case_id', $caseIds)
            ->orderByRaw("
                CASE
                    WHEN meta->>'chunk_index' IS NOT NULL
                    THEN (meta->>'chunk_index')::int
                    ELSE id
                END ASC
            ")
            ->get(['id', 'case_id', 'case_num', 'dispute_subject', 'case_date',
                   'category', 'result', 'claim_type', 'kind', 'chamber',
                   'court', 'content', 'meta', 'case_type']);
    }

    /**
     * Formats a float array as a pgvector literal string.
     */
    public static function formatVector(array $embedding): string
    {
        return '[' . implode(',', array_map('floatval', $embedding)) . ']';
    }
}
