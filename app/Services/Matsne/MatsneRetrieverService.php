<?php

namespace App\Services\Matsne;

use App\Services\AI\OllamaEmbeddingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MatsneRetrieverService
{
    private const THRESHOLDS  = [0.60, 0.45, 0.35];
    private const CHUNK_LIMIT = 40;
    private const DOC_LIMIT   = 5;

    public function __construct(
        private readonly OllamaEmbeddingService $embedder,
    ) {}

    public function embedQuery(string $query): array
    {
        return $this->embedder->embed($query);
    }

    /**
     * Retrieve matsne documents with optional domain filtering and lex specialis ordering.
     *
     * When $domains is non-empty, results are filtered to those domains first
     * (falls back to all domains if domain-filtered search returns nothing).
     *
     * Lex specialis sort: hierarchy_level ASC → similarity DESC
     *   1=constitution → 7=local act
     *
     * @param  string[] $domains  Legal domains to filter by (empty = no filter)
     * @return array<int, array{matsne_id, title, doc_type, issuer, is_active, excerpt, similarity, url, hierarchy_level}>
     */
    public function retrieve(string $query, int $limit = self::DOC_LIMIT, array $embedding = [], array $domains = [], ?int $relevantYear = null): array
    {
        if (empty($embedding)) {
            $embedding = $this->embedder->embed($query);
        }

        $year            = $relevantYear ?? (int) date('Y');
        $keywordResults  = $this->keywordSearch($query, $limit * 2, $domains, $year);
        $semanticResults = [];

        if (!empty($embedding)) {
            $semanticResults = $this->vectorSearch($query, $embedding, $limit, $domains, $year);
        } else {
            Log::debug('MatsneRetriever: embedding failed, keyword-only mode');
        }

        // Domain-filtered results
        $results = $this->mergeResults($keywordResults, $semanticResults, $limit);

        // If domain filter yields nothing, retry without filter (graceful fallback)
        if (empty($results) && !empty($domains)) {
            Log::debug('MatsneRetriever: domain filter returned empty, retrying without filter', ['domains' => $domains]);
            $kwFallback  = $this->keywordSearch($query, $limit * 2, [], $year);
            $vecFallback = !empty($embedding) ? $this->vectorSearch($query, $embedding, $limit, [], $year) : [];
            $results     = $this->mergeResults($kwFallback, $vecFallback, $limit);
        }

        Log::debug('MatsneRetriever: complete', [
            'query'    => mb_substr($query, 0, 80),
            'keyword'  => count($keywordResults),
            'semantic' => count($semanticResults),
            'merged'   => count($results),
            'domains'  => $domains,
        ]);

        return $results;
    }

    private function keywordSearch(string $query, int $limit, array $domains, int $year): array
    {
        $lines = array_filter(
            preg_split('/\n+/u', trim($query)),
            fn($l) => mb_strlen(trim($l)) >= 3
        );
        $phrases = array_values(array_map('trim', $lines));

        if (count($phrases) <= 1) {
            $phrases = array_values(array_filter(
                preg_split('/\s+/u', mb_strtolower(trim($query))),
                fn($w) => mb_strlen($w) >= 4
            ));
        }

        if (empty($phrases)) {
            return [];
        }

        $conditions = [];
        $scoreParts = [];
        $params     = [];
        foreach ($phrases as $i => $phrase) {
            $key = "kw{$i}";
            $pat = '%' . mb_strtolower($phrase) . '%';
            $conditions[] = "(LOWER(mc.title) LIKE :{$key}_title OR LOWER(mc.content) LIKE :{$key}_content)";
            $scoreParts[] = "(CASE WHEN LOWER(mc.title) LIKE :{$key}_score_title THEN 2 ELSE 0 END
                + CASE WHEN LOWER(mc.content) LIKE :{$key}_score_content THEN 1 ELSE 0 END)";
            $params["{$key}_title"]         = $pat;
            $params["{$key}_content"]       = $pat;
            $params["{$key}_score_title"]   = $pat;
            $params["{$key}_score_content"] = $pat;
        }

        $where       = implode(' OR ', $conditions);
        $scoreSql    = implode(' + ', $scoreParts);
        $domainSql   = $this->domainSql($domains);
        $titleQualitySql = $this->titleQualitySql($query);
        $params['lim']       = $limit;
        $params['year_from'] = $year;
        $params['year_to']   = $year;

        try {
            $rows = DB::connection('pgvector')->select("
                WITH candidates AS (
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
                        COALESCE(md.hierarchy_level, 5) AS hierarchy_level,
                        ({$scoreSql}) AS keyword_hits,
                        {$titleQualitySql} AS title_quality
                    FROM matsne_chunks_v2 mc
                    LEFT JOIN matsne_documents md ON md.matsne_id = mc.matsne_id
                    WHERE mc.is_active = true
                      AND ({$where})
                      AND (mc.effective_from_year IS NULL OR mc.effective_from_year <= :year_from)
                      AND (mc.effective_to_year   IS NULL OR mc.effective_to_year   >= :year_to)
                      {$domainSql}
                ),
                ranked AS (
                    SELECT *,
                        ROW_NUMBER() OVER (
                            PARTITION BY matsne_id
                            ORDER BY keyword_hits * title_quality DESC, chunk_index ASC
                        ) AS row_num
                    FROM candidates
                )
                SELECT *
                FROM ranked
                WHERE row_num = 1
                ORDER BY keyword_hits * title_quality DESC, hierarchy_level ASC
                LIMIT :lim
            ", array_merge($params, $this->domainBindings($domains)));
        } catch (\Throwable $e) {
            Log::warning('MatsneRetriever: keywordSearch failed', ['error' => $e->getMessage()]);
            return [];
        }

        $byDoc = [];
        foreach ($rows as $row) {
            $id = $row->matsne_id;
            $keywordScore = (float) $row->keyword_hits * (float) $row->title_quality;
            $rankScore = min(0.58, 0.30 + 0.06 * $keywordScore);
            $byDoc[$id] = [
                'matsne_id'           => $id,
                'title'               => $row->title ?? "Matsne #{$id}",
                'doc_type'            => $row->doc_type,
                'issuer'              => $row->issuer,
                'is_active'           => $row->is_active,
                'effective_from_year' => $row->effective_from_year,
                'effective_to_year'   => $row->effective_to_year,
                'similarity'          => $rankScore,
                '_rank_score'         => $rankScore,
                'excerpt'             => mb_substr($row->content ?? '', 0, 1500),
                'url'                 => "https://matsne.gov.ge/ka/document/view/{$id}/0",
                'hierarchy_level'     => (int) ($row->hierarchy_level ?? 5),
                '_source'             => 'keyword',
            ];
        }

        return array_values($byDoc);
    }

    private function vectorSearch(string $query, array $embedding, int $limit, array $domains, int $year): array
    {
        $vec         = '[' . implode(',', $embedding) . ']';
        $domainSql   = $this->domainSql($domains);
        $domainBinds = $this->domainBindings($domains);
        $titleQualitySql = $this->titleQualitySql($query);

        foreach (self::THRESHOLDS as $threshold) {
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
                    {$titleQualitySql} AS title_quality,
                    (1 - (mc.embedding <=> :emb_rank::vector)) * ({$titleQualitySql}) AS ranking_score,
                    COALESCE(md.hierarchy_level, 5) AS hierarchy_level
                FROM matsne_chunks_v2 mc
                LEFT JOIN matsne_documents md ON md.matsne_id = mc.matsne_id
                WHERE mc.embedding IS NOT NULL
                  AND mc.is_active = true
                  AND 1 - (mc.embedding <=> :emb2::vector) >= :threshold
                  AND (mc.effective_from_year IS NULL OR mc.effective_from_year <= :year_from)
                  AND (mc.effective_to_year   IS NULL OR mc.effective_to_year   >= :year_to)
                  {$domainSql}
                ORDER BY ranking_score DESC
                LIMIT :chunk_limit
            ", array_merge([
                'emb'         => $vec,
                'emb2'        => $vec,
                'emb_rank'    => $vec,
                'threshold'   => $threshold,
                'year_from'   => $year,
                'year_to'     => $year,
                'chunk_limit' => self::CHUNK_LIMIT,
            ], $domainBinds));

            if (!empty($rows)) {
                return $this->groupByDocument($rows, $limit);
            }
        }

        return [];
    }

    private function mergeResults(array $keyword, array $semantic, int $limit): array
    {
        $merged = [];
        $seen   = [];

        foreach ($keyword as $doc) {
            $id = $doc['matsne_id'];
            $merged[$id] = $doc;
            $seen[$id]   = true;
        }

        foreach ($semantic as $doc) {
            $id = $doc['matsne_id'];
            $rankScore = $doc['_rank_score'] ?? $doc['similarity'];
            $existingRankScore = $merged[$id]['_rank_score'] ?? $merged[$id]['similarity'] ?? 0;
            if (!isset($seen[$id]) || $rankScore > $existingRankScore) {
                $merged[$id] = $doc;
            }
        }

        usort($merged, function ($a, $b) {
            $rankA = $a['_rank_score'] ?? $a['similarity'] ?? 0;
            $rankB = $b['_rank_score'] ?? $b['similarity'] ?? 0;
            $similarityOrder = $rankB <=> $rankA;
            if ($similarityOrder !== 0) return $similarityOrder;

            $hlA = (int) ($a['hierarchy_level'] ?? 5);
            $hlB = (int) ($b['hierarchy_level'] ?? 5);
            return $hlA <=> $hlB;
        });

        return array_slice(array_values($merged), 0, $limit);
    }

    private function groupByDocument(array $rows, int $limit): array
    {
        $byDoc = [];

        foreach ($rows as $row) {
            $id = $row->matsne_id;
            if (!isset($byDoc[$id])) {
                $byDoc[$id] = [
                    'matsne_id'           => $id,
                    'title'               => $row->title ?? "Matsne #{$id}",
                    'doc_type'            => $row->doc_type,
                    'issuer'             => $row->issuer,
                    'is_active'           => $row->is_active,
                    'effective_from_year' => $row->effective_from_year,
                    'effective_to_year'   => $row->effective_to_year,
                    'similarity'          => (float) $row->similarity,
                    '_rank_score'         => (float) $row->ranking_score,
                    'hierarchy_level'     => (int) ($row->hierarchy_level ?? 5),
                    'chunks'              => [],
                ];
            }

            if ((float) $row->similarity > $byDoc[$id]['similarity']) {
                $byDoc[$id]['similarity'] = (float) $row->similarity;
            }
            if ((float) $row->ranking_score > $byDoc[$id]['_rank_score']) {
                $byDoc[$id]['_rank_score'] = (float) $row->ranking_score;
            }

            $byDoc[$id]['chunks'][] = $row->content;
        }

        usort($byDoc, function ($a, $b) {
            $similarityOrder = $b['_rank_score'] <=> $a['_rank_score'];
            if ($similarityOrder !== 0) return $similarityOrder;

            return $a['hierarchy_level'] <=> $b['hierarchy_level'];
        });

        return array_map(function (array $doc) {
            $doc['excerpt'] = mb_substr(implode("\n\n", array_slice($doc['chunks'], 0, 3)), 0, 1500);
            $doc['url']     = "https://matsne.gov.ge/ka/document/view/{$doc['matsne_id']}/0";
            unset($doc['chunks']);
            return $doc;
        }, array_slice($byDoc, 0, $limit));
    }

    // ── Domain filter helpers ─────────────────────────────────────────────────

    private function domainSql(array $domains): string
    {
        $domains = $this->normalizeDomains($domains);
        if (empty($domains)) {
            return '';
        }
        $placeholders = implode(',', array_map(fn($i) => ":dom{$i}", array_keys($domains)));
        return "AND md.domain IN ({$placeholders})";
    }

    private function domainBindings(array $domains): array
    {
        $domains = $this->normalizeDomains($domains);
        $bindings = [];
        foreach (array_values($domains) as $i => $domain) {
            $bindings["dom{$i}"] = $domain;
        }
        return $bindings;
    }

    private function normalizeDomains(array $domains): array
    {
        $normalized = [];
        $aliases = [
            'civil_law' => 'civil',
            'civil_procedure' => 'procedure',
            'administrative' => 'admin',
            'property' => 'civil',
            'family' => 'civil',
        ];

        foreach ($domains as $domain) {
            $normalized[] = $aliases[$domain] ?? $domain;
        }

        return array_values(array_unique($normalized));
    }

    private function titleQualitySql(string $query): string
    {
        if (str_contains(mb_strtolower($query), 'ცვლილებ')) {
            return '1.0';
        }

        return "CASE
            WHEN LOWER(mc.title) IN (
                'საქართველოს სამოქალაქო კოდექსი',
                'საქართველოს სამოქალაქო საპროცესო კოდექსი',
                'საქართველოს სისხლის სამართლის კოდექსი',
                'საქართველოს სისხლის სამართლის საპროცესო კოდექსი',
                'საქართველოს ზოგადი ადმინისტრაციული კოდექსი',
                'საქართველოს ადმინისტრაციული საპროცესო კოდექსი',
                'საქართველოს შრომის კოდექსი',
                'საქართველოს საგადასახადო კოდექსი',
                'საქართველოს კონსტიტუცია',
                'მეწარმეთა შესახებ',
                'ადამიანის უფლებათა და ძირითად თავისუფლებათა დაცვის კონვენცია'
            ) THEN 1.08
            WHEN LOWER(mc.title) LIKE '%ცვლილების შეტან%'
              OR LOWER(mc.title) LIKE '%ცვლილებების შეტან%'
              OR LOWER(mc.title) LIKE '%დამატების შეტან%'
              OR LOWER(mc.title) LIKE '%დამატებების შეტან%'
              OR LOWER(mc.title) LIKE '%ცვლილებებისა და დამატებების%'
              OR LOWER(mc.title) LIKE '%ძალადაკარგულად გამოცხად%'
              OR LOWER(mc.title) LIKE '%კანონის პროექტ%'
            THEN 0.35
            ELSE 1.0
        END";
    }
}
