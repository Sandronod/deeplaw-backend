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
    // Abbreviation → matsne_id list (ordered by specificity)
    private const LAW_MAP = [
        'სსკ'       => [29962],   // სამოქ. საπроцесო კოდ. (CPC)
        'სამ.კოდ'   => [31702],   // სამოქ. კოდ. (CC)
        'სამოქ.კოდ' => [31702],
        'სამ.კ'     => [31702],
        'სკ'        => [31702],   // სამოქ. კოდ. — მოკლე ფორმა (CC) ← ყველაზე ხშირი!
        'სსსკ'      => [90034],   // სისხლის სამართლის საπроцесო კოდ.
        'სსს'       => [90034],
        'ზაკ'       => [16270],   // ზოგ. ადმ. კოდ.
        'ადმ.საπ'   => [16492],   // ადმ. საπроцесო კოდ.
        'ადმ.კოდ'   => [16492],
        'შრ.კოდ'    => [63789],   // შრომის კოდ.
        'შრ.კ'      => [63789],
    ];

    // Domain → law IDs to try when no abbreviation is present
    private const DOMAIN_LAWS = [
        'civil_procedure' => [29962],
        'civil_law'       => [31702],
        'criminal'        => [90034],
        'administrative'  => [16270, 16492],
        'labor'           => [63789],
    ];

    /**
     * Detect article references and return matching chunks.
     * Results are in MatsneRetrieverService format, with similarity=0.95 and _source='article_detector'.
     *
     * @param  string[] $domains  TriageResult domains for fallback law inference
     * @return array<int, array{matsne_id, title, doc_type, issuer, is_active, excerpt, similarity, url, hierarchy_level}>
     */
    public function detect(string $question, array $domains = []): array
    {
        $refs = $this->extractRefs($question);

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
            $lawIds     = !empty($ref['law_ids']) ? $ref['law_ids'] : $this->inferLawIds($domains);

            foreach ($lawIds as $matsneId) {
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
     * Each entry: ['num' => int, 'code' => string, 'law_ids' => int[]|null]
     */
    private function extractRefs(string $text): array
    {
        $refs        = [];
        $seenNums    = [];

        // ── Pass 1: references with explicit law abbreviation ─────────────────
        // Matches: "სკ-ის 54-ე მუხლი", "სსკ-ის 21-ე მუხლი", "სამ.კოდ-ის 3-ე მუხლი"
        // NOTE: სკ must come before სსკ so it is not swallowed by a longer match.
        $pattern1 = '/(?P<code>სკ(?!ს)|სსკ|სამ\.?კოდ[ი]?|სამოქ\.?კოდ[ი]?|სამ\.?კ|სსსკ|სსს|ზაკ|ადმ\.?საπ(?:\.?კოდ[ი]?)?|ადმ\.?კოდ[ი]?|შრ\.?კოდ[ი]?|შრ\.?კ)(?:-ის|-ი)?\s+(?:მე-)?(?P<num>\d+)[-–]?(?:ე|ელ(?:ი|ა)?|ლ(?:ი|ა)?)?\s+მუხლ[ი|ს|ით|ად|ების|ებს]?/u';

        if (preg_match_all($pattern1, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $num    = (int) $m['num'];
                $code   = $m['code'];
                $lawIds = $this->resolveLawIds($code);
                $refs[] = ['num' => $num, 'code' => $code, 'law_ids' => $lawIds];
                $seenNums[$num] = true;
            }
        }

        // ── Pass 2: standalone references (no abbreviation) ───────────────────
        // Matches: "21-ე მუხლი", "მე-9 მუხლი", "მე-9-ე მუხლი"
        $pattern2 = '/(?:მე-)?(?P<num>\d+)[-–]?(?:ე|ელ(?:ი|ა)?|ლ(?:ი|ა)?)?\s+მუხლ[ი|ს|ით|ად|ების|ებს]?/u';

        if (preg_match_all($pattern2, $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $num = (int) $m['num'];
                if (!isset($seenNums[$num])) {
                    $refs[]          = ['num' => $num, 'code' => '', 'law_ids' => null];
                    $seenNums[$num]  = true;
                }
            }
        }

        return $refs;
    }

    private function resolveLawIds(string $code): array
    {
        // Normalise: collapse dots and spaces
        $norm = mb_strtolower(str_replace([' '], [''], $code));

        foreach (self::LAW_MAP as $abbrev => $ids) {
            $abbrevNorm = mb_strtolower(str_replace([' '], [''], $abbrev));
            if ($norm === $abbrevNorm || str_starts_with($norm, $abbrevNorm)) {
                return $ids;
            }
        }

        // Fallback: try prefix match on full code
        foreach (self::LAW_MAP as $abbrev => $ids) {
            $abbrevNorm = mb_strtolower(str_replace(['.', ' '], ['', ''], $abbrev));
            $codeNorm   = mb_strtolower(str_replace(['.', ' '], ['', ''], $code));
            if (str_starts_with($codeNorm, $abbrevNorm)) {
                return $ids;
            }
        }

        return [];
    }

    private function inferLawIds(array $domains): array
    {
        $ids = [];
        foreach ($domains as $domain) {
            foreach (self::DOMAIN_LAWS[$domain] ?? [] as $id) {
                if (!in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
        }
        return $ids;
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
            'excerpt'             => mb_substr($excerpt, 0, 2000),
            'url'                 => "https://matsne.gov.ge/ka/document/view/{$matsneId}/0",
            'hierarchy_level'     => (int) ($firstRow->hierarchy_level ?? 5),
            '_source'             => 'article_detector',
            '_article_num'        => $articleNum,
        ];
    }
}
