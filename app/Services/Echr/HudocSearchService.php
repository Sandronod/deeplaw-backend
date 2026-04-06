<?php

namespace App\Services\Echr;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HUDOC public search API client.
 *
 * API endpoint: https://hudoc.echr.coe.int/app/query/results
 * Docs / schema: inferred from public HUDOC portal
 *
 * No authentication required.
 */
class HudocSearchService
{
    private const BASE_URL = 'https://hudoc.echr.coe.int';
    private const TIMEOUT  = 15;

    private const SELECT_FIELDS = [
        'itemid', 'docname', 'appno', 'judgementdate', 'decisiondate',
        'importance', 'documentcollectionid2', 'originatingbody',
        'article', 'conclusion', 'languageisocode', 'respondent',
        'kpdate', 'typedescription',
    ];

    // ── Public search methods ──────────────────────────────────────────────────

    /**
     * Full-text / topic keyword search.
     */
    public function searchByKeyword(string $query, int $limit = 10): array
    {
        $escaped = $this->escapeQuery($query);
        $ecmQuery = "contentsitename:ECHR AND ({$escaped})";

        return $this->search($ecmQuery, $limit);
    }

    /**
     * Search by ECHR application number (e.g. "16812/11").
     */
    public function searchByApplicationNumber(string $appNo): array
    {
        $escaped  = $this->escapeQuery($appNo);
        $ecmQuery = "contentsitename:ECHR AND appno:{$escaped}";

        return $this->search($ecmQuery, 5);
    }

    /**
     * Search by Convention article number (e.g. "6", "8", "P1-1").
     */
    public function searchByArticle(string $article, int $limit = 10): array
    {
        $ecmQuery = "contentsitename:ECHR AND article:{$article}";

        return $this->search($ecmQuery, $limit);
    }

    /**
     * Search Georgia-related cases (respondent = GEO).
     */
    public function searchGeorgiaRelated(string $query, int $limit = 10): array
    {
        $escaped  = $this->escapeQuery($query);
        $ecmQuery = "contentsitename:ECHR AND respondentStatesList:GEO AND ({$escaped})";

        return $this->search($ecmQuery, $limit);
    }

    /**
     * Bulk seed: Georgia cases with importance 1 or 2, newest first.
     */
    public function searchGeorgiaSeedCases(int $limit = 50, int $start = 0): array
    {
        $ecmQuery = "contentsitename:ECHR AND respondentStatesList:GEO"
                  . " AND (importance:1 OR importance:2)";

        return $this->search($ecmQuery, $limit, $start);
    }

    /**
     * Bulk seed: top Article 6 cases with importance 1 or 2.
     */
    public function searchArticleSeedCases(string $article, int $limit = 50, int $start = 0): array
    {
        $ecmQuery = "contentsitename:ECHR AND article:{$article}"
                  . " AND (importance:1 OR importance:2)";

        return $this->search($ecmQuery, $limit, $start);
    }

    // ── Internal ──────────────────────────────────────────────────────────────

    private function search(string $ecmQuery, int $limit, int $start = 0): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT)
                ->get(self::BASE_URL . '/app/query/results', [
                    'query'  => $ecmQuery,
                    'select' => implode(',', self::SELECT_FIELDS),
                    'sort'   => 'kpdate Descending',
                    'start'  => $start,
                    'length' => $limit,
                ]);

            if ($response->failed()) {
                Log::warning('HudocSearchService: HTTP error', [
                    'status' => $response->status(),
                    'query'  => $ecmQuery,
                ]);
                return [];
            }

            $data = $response->json();

            if (!isset($data['results']) || !is_array($data['results'])) {
                Log::debug('HudocSearchService: empty/unexpected response', [
                    'query' => $ecmQuery,
                    'body'  => substr($response->body(), 0, 300),
                ]);
                return [];
            }

            Log::debug('HudocSearchService: search results', [
                'query'       => $ecmQuery,
                'resultcount' => $data['resultcount'] ?? 0,
                'returned'    => count($data['results']),
            ]);

            // Unwrap: results are [{columns: {...}}, ...]
            return array_map(
                fn($item) => $item['columns'] ?? $item,
                $data['results']
            );

        } catch (\Throwable $e) {
            Log::error('HudocSearchService: exception', [
                'error' => $e->getMessage(),
                'query' => $ecmQuery,
            ]);
            return [];
        }
    }

    /**
     * Escape special ECM query characters (keep alphanumeric, spaces, /-.).
     */
    private function escapeQuery(string $q): string
    {
        // Remove ECM operators to prevent injection
        $q = preg_replace('/[+\-!(){}[\]^"~*?:\\\\]/', ' ', $q);
        return '"' . trim(preg_replace('/\s+/', ' ', $q)) . '"';
    }
}
