<?php

namespace App\Services\Matsne;

use Illuminate\Support\Facades\Log;

class MatsneHtmlParserService
{
    /**
     * Parse Matsne HTML into structured articles.
     *
     * Returns array of:
     * [
     *   'title'         => string   (law title),
     *   'articles'      => [
     *     ['article_num'   => 'მუხლი 5',
     *      'article_title' => 'განმარტება',
     *      'content'       => '...' ],
     *     ...
     *   ]
     * ]
     */
    public function parse(string $html, int $matsneId): array
    {
        // Suppress HTML parsing warnings
        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        $title    = $this->extractTitle($xpath, $matsneId);
        $meta     = $this->extractMetadata($xpath);
        $articles = $this->extractArticles($xpath);

        if (empty($articles)) {
            $articles = $this->fallbackParagraphChunks($xpath);
        }

        Log::info('MatsneHtmlParserService: parsed', [
            'matsne_id'     => $matsneId,
            'title'         => $title,
            'article_count' => count($articles),
            'meta'          => $meta,
        ]);

        return [
            'title'    => $title,
            'articles' => $articles,
            'meta'     => $meta,
        ];
    }

    /**
     * Extract document metadata from Matsne page.
     * Matsne uses label-value table rows or definition lists.
     * Date format: DD/MM/YYYY
     *
     * Returns:
     * [
     *   'doc_number'     => '1234',
     *   'issuer'         => 'საქართველოს პარლამენტი',
     *   'signing_date'   => '1997-10-28',   // ISO
     *   'publish_date'   => '1997-11-21',
     *   'effective_from' => '1998-01-01',
     *   'effective_to'   => null,
     *   'is_active'      => true,
     * ]
     */
    public function extractMetadata(\DOMXPath $xpath): array
    {
        $meta = [
            'doc_type'       => null,
            'doc_number'     => null,
            'issuer'         => null,
            'signing_date'   => null,
            'publish_date'   => null,
            'effective_from' => null,
            'effective_to'   => null,
            'is_active'      => true,
        ];

        // Build a flat map of label → value from all table rows and dt/dd pairs
        $pairs = $this->extractLabelValuePairs($xpath);

        foreach ($pairs as $label => $value) {
            $l = mb_strtolower(trim($label));

            if (str_contains($l, 'ტიპი') || str_contains($l, 'type')) {
                $meta['doc_type'] = $this->cleanText($value);
            }
            if (str_contains($l, 'ნომერი') || str_contains($l, 'number')) {
                $meta['doc_number'] = $this->cleanText($value);
            }
            if (str_contains($l, 'მიმღები') || str_contains($l, 'გამომცემი') || str_contains($l, 'issuer')) {
                $meta['issuer'] = $this->cleanText($value);
            }
            if (str_contains($l, 'მიღების') || str_contains($l, 'ხელმოწერ') || str_contains($l, 'adoption')) {
                $meta['signing_date'] = $this->parseDate($value);
            }
            if (str_contains($l, 'გამოქვეყნებ')) {
                // "გამოქვეყნების წყარო, თარიღი" contains source + date, extract date
                $meta['publish_date'] = $this->parseDateFromMixed($value);
            }
            if (str_contains($l, 'ძალაში შეს') || str_contains($l, 'effective')) {
                $meta['effective_from'] = $this->parseDate($value);
            }
            if (str_contains($l, 'ძალადაკარ') || str_contains($l, 'ძალა დაკარ') || str_contains($l, 'expiry')) {
                $meta['effective_to'] = $this->parseDate($value);
            }
            if (str_contains($l, 'სტატუს') || str_contains($l, 'status')) {
                $meta['is_active'] = ! str_contains(mb_strtolower($value), 'გაუქმ');
            }
        }

        // Detect "გაუქმებული" anywhere on page if not found in table
        if ($meta['is_active']) {
            $bodyText = mb_strtolower($xpath->evaluate('string(//body)'));
            if (str_contains($bodyText, 'გაუქმებულია') || str_contains($bodyText, 'ძალადაკარგულ')) {
                $meta['is_active'] = false;
            }
        }

        return $meta;
    }

    /**
     * Extract label→value pairs from:
     * - <tr><th>label</th><td>value</td></tr>
     * - <tr><td class="label">label</td><td>value</td></tr>
     * - <dt>label</dt><dd>value</dd>
     * - <span class="field-label">label</span> + <span class="field-item">value</span>
     */
    private function extractLabelValuePairs(\DOMXPath $xpath): array
    {
        $pairs = [];

        // Strategy 1: <tr> with <th> or label-like first <td>
        $rows = $xpath->query('//table//tr');
        foreach ($rows as $row) {
            $cells = $xpath->query('.//th | .//td', $row);
            if ($cells->length >= 2) {
                $label = $this->cleanText($cells->item(0)->textContent);
                $value = $this->cleanText($cells->item(1)->textContent);
                if ($label && $value) {
                    $pairs[$label] = $value;
                }
            }
        }

        // Strategy 2: <dt>/<dd> definition lists
        $dts = $xpath->query('//dl/dt');
        foreach ($dts as $dt) {
            $label = $this->cleanText($dt->textContent);
            $dd    = $xpath->query('following-sibling::dd[1]', $dt)->item(0);
            if ($dd && $label) {
                $pairs[$label] = $this->cleanText($dd->textContent);
            }
        }

        // Strategy 3: field-label / field-item spans (Drupal-style Matsne layout)
        $labels = $xpath->query('//*[contains(@class,"field-label")]');
        foreach ($labels as $labelNode) {
            $label = $this->cleanText($labelNode->textContent);
            // Look for sibling or nearby field-item
            $item = $xpath->query(
                'following-sibling::*[contains(@class,"field-item")][1] | ..//*[contains(@class,"field-item")][1]',
                $labelNode
            )->item(0);
            if ($item && $label) {
                $pairs[$label] = $this->cleanText($item->textContent);
            }
        }

        return $pairs;
    }

    /** Parse DD/MM/YYYY → YYYY-MM-DD (ISO). Returns null on failure. */
    private function parseDate(string $value): ?string
    {
        // Try DD/MM/YYYY
        if (preg_match('/(\d{1,2})\/(\d{1,2})\/(\d{4})/', $value, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        // Try YYYY-MM-DD already
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $value, $m)) {
            return $m[0];
        }
        return null;
    }

    /** For fields like "პარლამენტის უწყებანი, 45, 21/11/1997" — extract last date. */
    private function parseDateFromMixed(string $value): ?string
    {
        preg_match_all('/(\d{1,2})\/(\d{1,2})\/(\d{4})/', $value, $matches, PREG_SET_ORDER);
        if (empty($matches)) {
            return null;
        }
        $last = end($matches);
        return sprintf('%04d-%02d-%02d', $last[3], $last[2], $last[1]);
    }

    // ── Title extraction ──────────────────────────────────────────────────────

    private function extractTitle(\DOMXPath $xpath, int $matsneId): string
    {
        // Try <h1> first
        $h1 = $xpath->query('//h1');
        if ($h1->length > 0) {
            $text = $this->cleanText($h1->item(0)->textContent);
            if (mb_strlen($text) > 5) return $text;
        }

        // Try page <title> tag
        $titleNodes = $xpath->query('//title');
        if ($titleNodes->length > 0) {
            $text = $this->cleanText($titleNodes->item(0)->textContent);
            // Strip " | matsne.gov.ge" suffix
            $text = preg_replace('/\s*[|·-].*$/u', '', $text);
            if (mb_strlen($text) > 5) return trim($text);
        }

        return "Matsne კანონი #{$matsneId}";
    }

    // ── Structured article extraction ─────────────────────────────────────────

    private function extractArticles(\DOMXPath $xpath): array
    {
        $articles = [];

        // Strategy 0a: id="DOCUMENT:N;ARTICLE:N;_Title" / "_Content" — standard Matsne layout
        $titleNodes = $xpath->query('//*[contains(@id,"ARTICLE") and contains(@id,"_Title")]');
        if ($titleNodes->length > 0) {
            foreach ($titleNodes as $titleNode) {
                $id          = $titleNode->getAttribute('id');
                $contentId   = str_replace('_Title', '_Content', $id);
                $contentNode = $xpath->query('//*[@id="' . $contentId . '"]')->item(0);

                $titleText   = $this->cleanText($titleNode->textContent);
                $contentText = $contentNode ? $this->cleanText($contentNode->textContent) : '';

                if (mb_strlen($titleText) < 2 && mb_strlen($contentText) < 10) continue;

                [$num, $title, $inline] = $this->parseArticleText($titleText);
                $articles[] = [
                    'article_num'   => $num ?: $titleText,
                    'article_title' => $title,
                    'content'       => $contentText ?: $inline,
                ];
            }
            if (!empty($articles)) return $articles;
        }

        // Strategy 0b: collect ALL _Content elements (including nested POINT inside ARTICLE)
        // Skip HEADER and FOOTER which are metadata, not legal content
        $contentNodes = $xpath->query('//*[contains(@id,"_Content") and contains(@id,"DOCUMENT")]');
        if ($contentNodes->length > 0) {
            $seen = [];
            foreach ($contentNodes as $contentNode) {
                $id = $contentNode->getAttribute('id');

                // Skip HEADER and FOOTER
                if (str_contains($id, 'HEADER') || str_contains($id, 'FOOTER')) {
                    continue;
                }

                // Skip if parent _Content already captured this text (avoid duplication)
                $contentText = $this->cleanText($contentNode->textContent);
                if (mb_strlen($contentText) < 10) continue;

                $hash = md5($contentText);
                if (isset($seen[$hash])) continue;
                $seen[$hash] = true;

                $titleId   = str_replace('_Content', '_Title', $id);
                $titleNode = $xpath->query('//*[@id="' . $titleId . '"]')->item(0);
                $titleText = $titleNode ? $this->cleanText($titleNode->textContent) : '';

                [$num, $title, $inline] = $this->parseArticleText($contentText);
                $articles[] = [
                    'article_num'   => $num ?: $titleText,
                    'article_title' => $title,
                    'content'       => $inline ?: $contentText,
                ];
            }
            if (!empty($articles)) return $articles;
        }

        // Strategy 0c: content directly in #maindoc (new-style doc without structured sub-parts).
        // Extract text nodes only — skip <script> and <style> children to avoid Handlebars
        // sidebar templates ({{...}}) polluting the content check.
        $maindoc = $xpath->query('//div[@id="maindoc"]')->item(0);
        if ($maindoc) {
            $text = $this->extractTextExcludingScripts($xpath, $maindoc);
            if (mb_strlen($text) > 50) {
                return $this->chunkTextIntoArticles($text);
            }
        }

        // Strategy 1: <div id="part_N"> — older Matsne layout
        $parts = $xpath->query('//*[@id and starts-with(@id, "part_")]');
        if ($parts->length > 0) {
            foreach ($parts as $part) {
                $extracted = $this->extractFromPart($xpath, $part);
                if ($extracted) {
                    $articles[] = $extracted;
                }
            }
            if (!empty($articles)) return $articles;
        }

        // Strategy 2: <p class="muxlixml"> — newer Matsne layout
        // Article header is a <p class="muxlixml">, content follows in sibling <p> tags
        $headers = $xpath->query('//p[contains(@class,"muxlixml")]');
        if ($headers->length > 0) {
            $articles = $this->extractFromMuxlixml($headers);
            if (!empty($articles)) return $articles;
        }

        // Strategy 3: class="article", "law-article", "norm"
        $articleNodes = $xpath->query(
            '//*[contains(@class,"article") or contains(@class,"law-article") or contains(@class,"norm")]'
        );
        foreach ($articleNodes as $node) {
            $text = $this->cleanText($node->textContent);
            if (mb_strlen($text) < 20) continue;
            [$num, $title, $content] = $this->parseArticleText($text);
            $articles[] = [
                'article_num'   => $num,
                'article_title' => $title,
                'content'       => $content ?: $text,
            ];
        }
        if (!empty($articles)) return $articles;

        // Strategy 4: div.Section1 — Word HTML export (abzacixml, MsoListParagraph, etc.)
        $section1 = $xpath->query(
            '//div[contains(@class,"Section1")] | //div[@class="Section1"]'
        )->item(0);
        if ($section1) {
            $text = $this->cleanText($section1->textContent);
            if (mb_strlen($text) > 50) {
                return $this->chunkTextIntoArticles($text);
            }
        }

        return $articles;
    }

    /**
     * Extract articles from <p class="muxlixml"> layout (newer Matsne pages).
     * Article heading is the muxlixml paragraph; content is the following sibling <p> tags.
     */
    private function extractFromMuxlixml(\DOMNodeList $headers): array
    {
        $articles = [];

        foreach ($headers as $header) {
            $headerText = $this->cleanText($header->textContent);

            // Only process actual article headings (skip chapter/book headings)
            if (!preg_match('/^მუხლი\s+[\d\w]+/u', $headerText)) {
                continue;
            }

            // Parse "მუხლი N. სათაური" format
            $articleNum   = '';
            $articleTitle = '';
            if (preg_match('/^(მუხლი\s+[\d\w¹²³⁴⁵⁶⁷⁸⁹]+)[.\s]+(.*)$/su', $headerText, $m)) {
                $articleNum   = trim($m[1]);
                $articleTitle = trim($m[2]);
            } else {
                $articleNum = trim($headerText);
            }

            // Collect content from following sibling <p> tags until next muxlixml
            $contentParts = [];
            $sibling      = $header->nextSibling;

            while ($sibling !== null) {
                if ($sibling->nodeType === XML_ELEMENT_NODE) {
                    // Stop at next article heading
                    $class = $sibling->getAttribute('class') ?? '';
                    if (str_contains($class, 'muxlixml')) {
                        break;
                    }

                    // Stop at chapter/section headings (bold blocks)
                    $nodeText = $this->cleanText($sibling->textContent);
                    if (preg_match('/^(კარი|თავი|ნაწილი|Chapter|Book)\s+/u', $nodeText)) {
                        break;
                    }

                    if (mb_strlen($nodeText) > 5) {
                        $contentParts[] = $nodeText;
                    }
                }
                $sibling = $sibling->nextSibling;
            }

            $content = implode(' ', $contentParts);
            if (mb_strlen($content) < 5) {
                $content = $articleTitle;
            }

            if (mb_strlen($articleNum) > 0) {
                $articles[] = [
                    'article_num'   => $articleNum,
                    'article_title' => $articleTitle,
                    'content'       => mb_substr($content, 0, 4000),
                ];
            }
        }

        return $articles;
    }

    private function extractFromPart(\DOMXPath $xpath, \DOMNode $part): ?array
    {
        $fullText = $this->cleanText($part->textContent);
        if (mb_strlen($fullText) < 30) return null;

        // Try to find article number heading inside the part
        $headings = $xpath->query('.//*[self::h1 or self::h2 or self::h3 or self::h4 or self::strong or self::b]', $part);

        $articleNum   = '';
        $articleTitle = '';

        foreach ($headings as $h) {
            $hText = $this->cleanText($h->textContent);
            if (preg_match('/^მუხლი\s+[\d\w¹²³⁴⁵⁶⁷⁸⁹]+/u', $hText)) {
                // "მუხლი 5. სათაური" — split on first dot/newline
                if (preg_match('/^(მუხლი\s+[\d\w¹²³⁴⁵⁶⁷⁸⁹]+)[.\s]+(.*)$/su', $hText, $m)) {
                    $articleNum   = trim($m[1]);
                    $articleTitle = trim($m[2]);
                } else {
                    $articleNum = trim($hText);
                }
                break;
            }
            // Chapter/book headings
            if (preg_match('/^(კარი|თავი|ნაწილი)\s+/u', $hText)) {
                $articleTitle = $hText;
                break;
            }
        }

        // Remove the heading text from full content to avoid duplication
        $content = $fullText;
        if ($articleNum) {
            $content = str_replace($articleNum, '', $content);
        }
        if ($articleTitle) {
            $content = str_replace($articleTitle, '', $content);
        }
        $content = $this->cleanText($content);

        if (mb_strlen($content) < 20 && mb_strlen($fullText) >= 20) {
            $content = $fullText;
        }

        return [
            'article_num'   => $articleNum,
            'article_title' => $articleTitle,
            'content'       => mb_substr($content, 0, 4000),
        ];
    }

    // ── Fallback: paragraph chunks ────────────────────────────────────────────

    private function chunkTextIntoArticles(string $text): array
    {
        $chunks   = [];
        $chunkNum = 1;
        $size     = 2000;
        $len      = mb_strlen($text);

        for ($offset = 0; $offset < $len; $offset += $size) {
            $chunk = trim(mb_substr($text, $offset, $size));
            if (mb_strlen($chunk) < 30) continue;
            $chunks[] = [
                'article_num'   => "ნაწილი {$chunkNum}",
                'article_title' => '',
                'content'       => $chunk,
            ];
            $chunkNum++;
        }

        return $chunks;
    }

    private function fallbackParagraphChunks(\DOMXPath $xpath): array
    {
        $chunks   = [];
        $buffer   = '';
        $chunkNum = 1;

        $paragraphs = $xpath->query(
            '//div[contains(@class,"field-item")]//p | //div[@id="content"]//p | //article//p | //div[contains(@class,"Section1")]//p'
        );

        foreach ($paragraphs as $p) {
            $text = $this->cleanText($p->textContent);
            if (mb_strlen($text) < 20) continue;

            $buffer .= ' ' . $text;

            // Chunk at ~2000 chars
            if (mb_strlen($buffer) >= 2000) {
                $chunks[] = [
                    'article_num'   => "ნაწილი {$chunkNum}",
                    'article_title' => '',
                    'content'       => trim($buffer),
                ];
                $buffer = '';
                $chunkNum++;
            }
        }

        if (mb_strlen(trim($buffer)) > 50) {
            $chunks[] = [
                'article_num'   => "ნაწილი {$chunkNum}",
                'article_title' => '',
                'content'       => trim($buffer),
            ];
        }

        return $chunks;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Collect all text nodes under $node, skipping <script> and <style> subtrees.
     * This avoids Handlebars sidebar templates polluting the extracted content.
     */
    private function extractTextExcludingScripts(\DOMXPath $xpath, \DOMNode $node): string
    {
        $texts = $xpath->query(
            './/text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::noscript)]',
            $node
        );

        $parts = [];
        foreach ($texts as $textNode) {
            $t = $this->cleanText($textNode->textContent);
            if (mb_strlen($t) > 0) {
                $parts[] = $t;
            }
        }

        return implode(' ', $parts);
    }

    private function parseArticleText(string $text): array
    {
        if (preg_match('/^(მუხლი\s+[\d\w¹²³⁴⁵⁶⁷⁸⁹]+)[.\s]+([^\n]{0,100})\n(.*)$/su', $text, $m)) {
            return [trim($m[1]), trim($m[2]), trim($m[3])];
        }
        if (preg_match('/^(მუხლი\s+[\d\w¹²³⁴⁵⁶⁷⁸⁹]+)[.\s]+(.*)$/su', $text, $m)) {
            return [trim($m[1]), '', trim($m[2])];
        }
        return ['', '', $text];
    }

    private function cleanText(string $text): string
    {
        // Remove zero-width chars, excessive whitespace
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text);
    }
}
