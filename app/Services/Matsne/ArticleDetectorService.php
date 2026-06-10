<?php

namespace App\Services\Matsne;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 3a — Article Number Detection.
 *
 * Detects explicit article references in the user question (e.g. "სსკ-ის 21-ე მუხლი",
 * "მე-9 მუხლი") and fetches the matching chunks directly from matsne_chunks_v2.
 * Returns results in the same format as MatsneRetrieverService::retrieve().
 */
class ArticleDetectorService
{
    public function __construct(
        private readonly CanonicalLawResolverService $lawResolver,
    ) {}

    /**
     * Detect article references and return matching chunks.
     * Results are in MatsneRetrieverService format, with similarity=0.95 and _source='article_detector'.
     *
     * @param  string[] $domains  TriageResult domains for fallback law inference
     * @return array<int, array{matsne_id, title, doc_type, issuer, is_active, excerpt, similarity, url, hierarchy_level}>
     */
    public function detect(string $question, array $domains = [], ?int $relevantYear = null): array
    {
        $refs = array_values(array_unique(array_merge(
            $this->extractRefs($question),
            $this->inferConceptRefs($question, $domains),
        ), SORT_REGULAR));

        if (empty($refs)) {
            return [];
        }

        Log::info('ArticleDetector: refs found', [
            'refs'    => array_map(fn($r) => "{$r['code']}:{$r['num']}", $refs),
            'domains' => $domains,
        ]);

        $results = [];
        $seen    = [];

        foreach ($refs as $ref) {
            $articleNum = $ref['num'];
            $documents  = $ref['code'] !== ''
                ? $this->lawResolver->resolveAlias($ref['code'], $relevantYear)
                : $this->lawResolver->resolveForDomains($domains, $relevantYear);

            foreach ($documents as $document) {
                $matsneId = $document['matsne_id'];
                $key = "{$matsneId}:{$articleNum}";
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $chunk = $this->fetchArticle($matsneId, $articleNum);
                if ($chunk !== null) {
                    $results[] = $chunk;
                    Log::info('ArticleDetector: hit', [
                        'matsne_id' => $matsneId,
                        'article'   => $articleNum,
                        'title'     => $chunk['title'],
                    ]);
                } else {
                    Log::info('ArticleDetector: miss', [
                        'matsne_id' => $matsneId,
                        'article'   => $articleNum,
                    ]);
                }
            }
        }

        return $results;
    }

    // ── Regex extraction ──────────────────────────────────────────────────────

    /**
     * Extract article references from question text.
     * Each entry: ['num' => int, 'code' => string]
     */
    private function extractRefs(string $text): array
    {
        $refs = [];

        $aliases = $this->lawResolver->aliases();
        usort($aliases, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        $aliasPattern = implode('|', array_map(
            fn (string $alias) => preg_quote($alias, '/'),
            $aliases
        ));

        $articleSuffix = '(?:[-–]?(?:ე|ელი|ლი))?';
        $articleWord   = 'მუხლ(?:ი|ის|ით|ად|ები|ების|ებს)?';
        $pattern1      = '/(?P<code>' . $aliasPattern . ')(?:-?ის|-?ი)?\s+(?:მე-)?(?P<num>\d+)'
            . $articleSuffix . '\s+' . $articleWord . '/u';

        if (preg_match_all($pattern1, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $refs[] = [
                    'num'  => (int) $m['num'],
                    'code' => trim($m['code']),
                ];
            }
        }

        $pattern2 = '/(?:მე-)?(?P<num>\d+)' . $articleSuffix . '\s+' . $articleWord . '/u';

        if (preg_match_all($pattern2, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $num = (int) $m['num'];
                $hasExplicitReference = collect($refs)->contains(
                    fn (array $ref) => $ref['num'] === $num && $ref['code'] !== ''
                );

                if (!$hasExplicitReference) {
                    $refs[] = ['num' => $num, 'code' => ''];
                }
            }
        }

        return array_values(array_unique($refs, SORT_REGULAR));
    }

    /**
     * High-precision concept triggers for norms that must not rely only on
     * vector similarity. Keep this list small and domain-gated.
     */
    private function inferConceptRefs(string $text, array $domains): array
    {
        $lower = mb_strtolower($text);
        $hasLaborDomain = (bool) array_intersect($domains, ['labor']);

        if (!$hasLaborDomain) {
            return [];
        }

        $terminationSignals = [
            'გათავისუფლ',
            'გაათავისუფლ',
            'შეწყვეტ',
            'დასაბუთ',
            'მოშლ',
        ];

        foreach ($terminationSignals as $signal) {
            if (str_contains($lower, $signal)) {
                return [
                    ['num' => 47, 'code' => 'შრომის კოდექს'],
                    ['num' => 48, 'code' => 'შრომის კოდექს'],
                ];
            }
        }

        return [];
    }

    // ── DB lookup ─────────────────────────────────────────────────────────────

    public function fetchArticle(int $matsneId, int $articleNum): ?array
    {
        // Build OR conditions for the various ways an article number can appear in text
        $pats = [
            "მუხლი {$articleNum}",          // most common: "მუხლი 21"
            "მუხლი {$articleNum}.",          // with period: "მუხლი 21."
            "{$articleNum}-ე მუხლი",         // ordinal: "21-ე მუხლი"
            "მე-{$articleNum} მუხლი",        // small ordinal: "მე-9 მუხლი"
            "მე-{$articleNum}-ე მუხლი",      // combined: "მე-9-ე მუხლი"
        ];

        $conditions = [];
        $bindings   = ['matsne_id' => $matsneId];

        foreach ($pats as $i => $pat) {
            $conditions[] = "LOWER(mc.content) LIKE :p{$i}";
            $bindings["p{$i}"] = '%' . mb_strtolower($pat) . '%';
        }

        $where = implode(' OR ', $conditions);

        try {
            $headingPattern = '(^|[\r\n])[[:space:]]*მუხლი[[:space:]]+'
                . $articleNum
                . '([.[:space:]]|$)';

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
                    COALESCE(md.hierarchy_level, 5) AS hierarchy_level
                FROM matsne_chunks_v2 mc
                LEFT JOIN matsne_documents md ON md.matsne_id = mc.matsne_id
                WHERE mc.matsne_id = :matsne_id
                  AND mc.content ~ :heading
                ORDER BY mc.chunk_index ASC
                LIMIT 4
            ", [
                'matsne_id' => $matsneId,
                'heading' => $headingPattern,
            ]);

            if (empty($rows)) {
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
                        COALESCE(md.hierarchy_level, 5) AS hierarchy_level
                    FROM matsne_chunks_v2 mc
                    LEFT JOIN matsne_documents md ON md.matsne_id = mc.matsne_id
                    WHERE mc.matsne_id = :matsne_id
                      AND ({$where})
                    ORDER BY mc.chunk_index ASC
                    LIMIT 3
                ", $bindings);
            }
        } catch (\Throwable $e) {
            Log::warning('ArticleDetector: DB query failed', [
                'matsne_id' => $matsneId,
                'article'   => $articleNum,
                'error'     => $e->getMessage(),
            ]);
            return null;
        }

        if (empty($rows)) {
            return null;
        }

        $firstRow = $rows[0];
        $excerpt  = implode("\n\n", array_map(fn($r) => $r->content, $rows));

        return [
            'matsne_id'           => $matsneId,
            'title'               => $firstRow->title ?? "Matsne #{$matsneId}",
            'doc_type'            => $firstRow->doc_type,
            'issuer'              => $firstRow->issuer,
            'is_active'           => $firstRow->is_active,
            'effective_from_year' => $firstRow->effective_from_year,
            'effective_to_year'   => $firstRow->effective_to_year,
            'similarity'          => 0.95,  // high — direct article match
            'excerpt'             => mb_substr($excerpt, 0, 6000),
            'url'                 => "https://matsne.gov.ge/ka/document/view/{$matsneId}/0",
            'hierarchy_level'     => (int) ($firstRow->hierarchy_level ?? 5),
            '_source'             => 'article_detector',
            '_article_num'        => $articleNum,
        ];
    }
}
