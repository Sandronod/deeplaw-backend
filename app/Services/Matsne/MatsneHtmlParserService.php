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
        $articles = $this->extractArticles($xpath);

        // Fallback: if structured extraction yields nothing, use paragraph chunking
        if (empty($articles)) {
            $articles = $this->fallbackParagraphChunks($xpath);
        }

        Log::info('MatsneHtmlParserService: parsed', [
            'matsne_id'     => $matsneId,
            'title'         => $title,
            'article_count' => count($articles),
        ]);

        return [
            'title'    => $title,
            'articles' => $articles,
        ];
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

    private function fallbackParagraphChunks(\DOMXPath $xpath): array
    {
        $chunks   = [];
        $buffer   = '';
        $chunkNum = 1;

        $paragraphs = $xpath->query('//div[contains(@class,"field-item")]//p | //div[@id="content"]//p | //article//p');

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
