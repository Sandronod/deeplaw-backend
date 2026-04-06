<?php

namespace App\Services\Echr;

/**
 * Parses raw HUDOC API columns into a normalized domain structure
 * ready for persistence via EchrRepositoryService.
 */
class HudocParserService
{
    private const EXCERPT_MAX_LEN = 1200;
    private const CHUNK_SIZE      = 800;  // target chars per paragraph chunk
    private const CHUNK_OVERLAP   = 80;

    /**
     * Parse a HUDOC result columns array (+ optional fetched full text)
     * into an array ready for EchrRepositoryService::upsert().
     *
     * @param  array       $columns  Raw HUDOC "columns" object from search results
     * @param  string|null $fullText Plain text fetched by HudocFetchService
     * @return array
     */
    public function parse(array $columns, ?string $fullText = null): array
    {
        $title    = $this->clean($columns['docname'] ?? '');
        $articles = $this->extractArticles($columns['article'] ?? '');
        $date     = $this->parseDate($columns['judgementdate'] ?? $columns['kpdate'] ?? null);
        $decDate  = $this->parseDate($columns['decisiondate'] ?? null);
        $itemId   = $columns['itemid'] ?? '';

        return [
            'hudoc_itemid'       => $itemId,
            'application_number' => $this->normalizeAppNo($columns['appno'] ?? null),
            'title'              => $title,
            'title_normalized'   => mb_strtolower(trim($title)),
            'judgment_date'      => $date,
            'decision_date'      => $decDate,
            'language'           => strtoupper($columns['languageisocode'] ?? 'ENG'),
            'importance'         => $this->parseImportance($columns['importance'] ?? null),
            'originating_body'   => $this->clean($columns['originatingbody'] ?? null),
            'document_type'      => strtoupper($columns['documentcollectionid2'] ?? ''),
            'chamber'            => null, // derived from originating_body in repo
            'respondent_state'   => $this->parseRespondent($columns['respondent'] ?? null),
            'source_url'         => "https://hudoc.echr.coe.int/eng#{\"itemid\":[\"$itemId\"]}",
            'summary'            => $this->extractSummary($columns['conclusion'] ?? null),
            'full_text'          => $fullText,
            'excerpt'            => $this->extractExcerpt($fullText ?? ''),
            'metadata'           => $columns,
            'articles'           => $articles,
            'chunks'             => $fullText ? $this->chunkText($fullText) : [],
        ];
    }

    // ── Extractors ────────────────────────────────────────────────────────────

    /**
     * Parse HUDOC article field (e.g. "6;8;10" or "6-1;P1-1").
     *
     * @return array  e.g. [['code' => '6', 'label' => 'Article 6'], ...]
     */
    public function extractArticles(string|array|null $raw): array
    {
        if (empty($raw)) {
            return [];
        }

        $raw = is_array($raw) ? implode(';', $raw) : (string) $raw;

        // Split by ; or ,
        $codes = preg_split('/[;,]+/', $raw);
        $result = [];

        foreach ($codes as $code) {
            $code = trim($code);
            if ($code === '') {
                continue;
            }

            // Normalise: "6-1" → "6", keep "P1-1", "P1-3", etc.
            $normalized = preg_match('/^P\d+-\d+/i', $code)
                ? strtoupper($code)
                : preg_replace('/-\d+$/', '', $code);

            $result[] = [
                'code'  => $normalized,
                'label' => $this->articleLabel($normalized),
            ];
        }

        // Deduplicate by code
        $seen = [];
        return array_values(array_filter($result, function ($a) use (&$seen) {
            if (isset($seen[$a['code']])) return false;
            $seen[$a['code']] = true;
            return true;
        }));
    }

    /**
     * Split full text into overlapping chunks for embedding.
     *
     * @return array  [['chunk_index' => int, 'content' => string], ...]
     */
    public function chunkText(string $text): array
    {
        if (mb_strlen($text) < 100) {
            return [['chunk_index' => 0, 'content' => $text]];
        }

        // Split on double-newline (paragraph breaks) first
        $paragraphs = preg_split('/\n{2,}/', $text);
        $chunks     = [];
        $buffer     = '';
        $index      = 0;

        foreach ($paragraphs as $para) {
            $para = trim($para);
            if ($para === '') continue;

            if (mb_strlen($buffer) + mb_strlen($para) > self::CHUNK_SIZE && $buffer !== '') {
                $chunks[] = ['chunk_index' => $index++, 'content' => $buffer];
                // Overlap: keep last CHUNK_OVERLAP chars
                $buffer = mb_substr($buffer, -self::CHUNK_OVERLAP) . "\n" . $para;
            } else {
                $buffer .= ($buffer !== '' ? "\n\n" : '') . $para;
            }
        }

        if ($buffer !== '') {
            $chunks[] = ['chunk_index' => $index, 'content' => $buffer];
        }

        return $chunks;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function extractExcerpt(string $text): string
    {
        return mb_substr(trim($text), 0, self::EXCERPT_MAX_LEN);
    }

    private function extractSummary(string|null $conclusion): ?string
    {
        if (empty($conclusion)) return null;

        // HUDOC conclusion is semicolon-separated: "Violation of Article 6;No violation of Article 8"
        $parts = preg_split('/[;]+/', $conclusion);
        return implode('. ', array_filter(array_map('trim', $parts)));
    }

    private function parseDate(?string $raw): ?string
    {
        if (empty($raw)) return null;

        // HUDOC dates: "07/07/2019" or "2019-07-07"
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $raw, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $raw)) {
            return substr($raw, 0, 10);
        }
        return null;
    }

    private function normalizeAppNo(string|null $raw): ?string
    {
        if (empty($raw)) return null;
        // Strip extra spaces, keep "16812/11" format
        return trim($raw);
    }

    private function parseImportance(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') return null;
        $i = (int) $raw;
        return ($i >= 1 && $i <= 4) ? $i : null;
    }

    private function parseRespondent(string|null $raw): ?string
    {
        if (empty($raw)) return null;
        // HUDOC respondent: "GEO" or "GEORGIA" → normalize to 3-letter code
        $upper = strtoupper(trim($raw));
        $map   = ['GEORGIA' => 'GEO', 'RUSSIA' => 'RUS', 'UKRAINE' => 'UKR'];
        return $map[$upper] ?? $upper;
    }

    private function clean(?string $s): string
    {
        return trim((string) $s);
    }

    private function articleLabel(string $code): string
    {
        $labels = [
            '2'    => 'Article 2 - Right to life',
            '3'    => 'Article 3 - Prohibition of torture',
            '4'    => 'Article 4 - Prohibition of slavery',
            '5'    => 'Article 5 - Right to liberty and security',
            '6'    => 'Article 6 - Right to a fair trial',
            '7'    => 'Article 7 - No punishment without law',
            '8'    => 'Article 8 - Right to respect for private and family life',
            '9'    => 'Article 9 - Freedom of thought, conscience and religion',
            '10'   => 'Article 10 - Freedom of expression',
            '11'   => 'Article 11 - Freedom of assembly and association',
            '13'   => 'Article 13 - Right to an effective remedy',
            '14'   => 'Article 14 - Prohibition of discrimination',
            '18'   => 'Article 18 - Limitation on use of restrictions',
            '34'   => 'Article 34 - Individual applications',
            '35'   => 'Article 35 - Admissibility criteria',
            '41'   => 'Article 41 - Just satisfaction',
            'P1-1' => 'Protocol 1, Article 1 - Protection of property',
            'P1-2' => 'Protocol 1, Article 2 - Right to education',
            'P1-3' => 'Protocol 1, Article 3 - Right to free elections',
        ];

        return $labels[strtoupper($code)] ?? "Article {$code}";
    }
}
