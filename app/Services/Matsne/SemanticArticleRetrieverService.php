<?php

namespace App\Services\Matsne;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Replaces ConceptDetectorService.
 *
 * Instead of a hardcoded keyword → article-number map, embeds the query
 * with bge-m3 (same model as matsne_chunks_v2) and returns the most
 * semantically similar law chunks — automatically, for any legal concept.
 */
class SemanticArticleRetrieverService
{
    private const THRESHOLD   = 0.35;
    private const CHUNK_LIMIT = 5;

    public function __construct(
        private readonly CanonicalLawResolverService $lawResolver,
    ) {}

    /**
     * @param  array $embedding  bge-m3 embedding (1024 dims) — from runPipeline()
     * @param  array $domains    TriageResult domains for narrowing the search
     * @return array             Same format as ArticleDetectorService::fetchArticle()
     */
    public function retrieve(array $embedding, array $domains = [], int $limit = self::CHUNK_LIMIT, ?int $relevantYear = null): array
    {
        if (empty($embedding)) {
            return [];
        }

        $year    = $relevantYear ?? (int) date('Y');
        $vec     = '[' . implode(',', $embedding) . ']';
        $lawIds  = array_column(
            $this->lawResolver->resolveForDomains($domains, $year),
            'matsne_id'
        );
        $idSql   = '';
        $activeSql = 'AND mc.is_active = true';
        $idBinds = [];

        if (!empty($lawIds)) {
            $placeholders = implode(',', array_map(fn($i) => ":lid{$i}", array_keys($lawIds)));
            $idSql = "AND mc.matsne_id IN ({$placeholders})";
            $activeSql = '';
            foreach (array_values($lawIds) as $i => $id) {
                $idBinds["lid{$i}"] = $id;
            }
        }

        try {
            $rows = DB::connection('pgvector')->select("
                SELECT
                    mc.matsne_id,
                    mc.title,
                    mc.doc_type,
                    mc.issuer,
                    mc.is_active,
                    mc.effective_from_year,
                    mc.effective_to_year,
                    mc.content,
                    mc.chunk_index,
                    1 - (mc.embedding <=> :emb::vector) AS similarity,
                    COALESCE(md.hierarchy_level, 5) AS hierarchy_level
                FROM matsne_chunks_v2 mc
                LEFT JOIN matsne_documents md ON md.matsne_id = mc.matsne_id
                WHERE mc.embedding IS NOT NULL
                  {$activeSql}
                  AND 1 - (mc.embedding <=> :emb2::vector) >= :threshold
                  AND (mc.effective_from_year IS NULL OR mc.effective_from_year <= :year_from)
                  AND (mc.effective_to_year   IS NULL OR mc.effective_to_year   >= :year_to)
                  {$idSql}
                ORDER BY (0.7 * (1 - (mc.embedding <=> :emb3::vector)) + 0.3 / COALESCE(md.hierarchy_level, 5)) DESC
                LIMIT :lim
            ", array_merge([
                'emb'       => $vec,
                'emb2'      => $vec,
                'emb3'      => $vec,
                'threshold' => self::THRESHOLD,
                'year_from' => $year,
                'year_to'   => $year,
                'lim'       => $limit,
            ], $idBinds));
        } catch (\Throwable $e) {
            Log::warning('SemanticArticleRetriever: query failed', ['error' => $e->getMessage()]);
            return [];
        }

        if (empty($rows)) {
            Log::warning('SemanticArticleRetriever: no results above threshold', [
                'threshold' => self::THRESHOLD,
                'domains'   => $domains,
            ]);
            return [];
        }

        $results = [];
        $seen    = [];

        foreach ($rows as $row) {
            $articleNum = $this->extractArticleNum($row->content);
            $key        = "{$row->matsne_id}:" . ($articleNum ?? $row->chunk_index);

            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $results[] = [
                'matsne_id'           => $row->matsne_id,
                'title'               => $row->title ?? "Matsne #{$row->matsne_id}",
                'doc_type'            => $row->doc_type,
                'issuer'              => $row->issuer,
                'is_active'           => $row->is_active,
                'effective_from_year' => $row->effective_from_year,
                'effective_to_year'   => $row->effective_to_year,
                'similarity'          => (float) $row->similarity,
                'excerpt'             => mb_substr($row->content, 0, 2000),
                'url'                 => "https://matsne.gov.ge/ka/document/view/{$row->matsne_id}/0",
                'hierarchy_level'     => (int) ($row->hierarchy_level ?? 5),
                '_source'             => 'semantic_article',
                '_article_num'        => $articleNum,
            ];
        }

        Log::info('SemanticArticleRetriever: hits', [
            'count'   => count($results),
            'domains' => $domains,
            'top_sim' => !empty($results) ? round($results[0]['similarity'], 3) : null,
        ]);

        return $results;
    }

    private function extractArticleNum(string $content): ?int
    {
        if (preg_match('/მუხლი\s+(\d+)/u', $content, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/(\d+)[-–]?(?:ე|ელ|ლ)?\s+მუხლ/u', $content, $m)) {
            return (int) $m[1];
        }
        return null;
    }

}
