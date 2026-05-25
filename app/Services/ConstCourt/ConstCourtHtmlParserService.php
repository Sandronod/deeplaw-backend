<?php

namespace App\Services\ConstCourt;

use Illuminate\Support\Facades\Log;

class ConstCourtHtmlParserService
{
    /**
     * Parse a Constitutional Court decision page.
     *
     * Returns:
     * [
     *   'case_number'      => 'N1/1/1794',
     *   'decision_type'    => 'გადაწყვეტილება',
     *   'decision_date'    => '2026-04-29',
     *   'publication_date' => '2026-04-29 17:27:00',
     *   'college'          => 'I კოლეგია',
     *   'judges'           => 'გ. კვერენჩხილაძე, ე. გოცირიძე',
     *   'respondent'       => 'საქართველოს პარლამენტი',
     *   'result'           => 'დაკმაყოფილდა',
     *   'case_name'        => '...',
     *   'content'          => 'full plain text',
     * ]
     */
    public function parse(string $html, int $legalId): array
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);

        $meta    = $this->parseMeta($xpath);
        $content = $this->parseContent($xpath);
        $title   = $this->parseTitle($xpath, $meta);

        Log::debug('ConstCourtHtmlParser: parsed', [
            'legal_id'    => $legalId,
            'case_number' => $meta['case_number'],
            'content_len' => mb_strlen($content),
        ]);

        return array_merge($meta, [
            'case_name' => $title,
            'content'   => $content,
        ]);
    }

    // ── Metadata ──────────────────────────────────────────────────────────────

    private function parseMeta(\DOMXPath $xpath): array
    {
        $meta = [
            'case_number'      => null,
            'decision_type'    => null,
            'decision_date'    => null,
            'publication_date' => null,
            'college'          => null,
            'judges'           => null,
            'respondent'       => null,
            'result'           => null,
        ];

        $rows = $xpath->query('//table[contains(@class,"legal-inner-table")]//tr');
        foreach ($rows as $row) {
            $cells = $xpath->query('.//td', $row);
            if ($cells->length < 2) continue;

            $label = $this->clean($cells->item(0)->textContent);
            $value = $this->clean($cells->item(1)->textContent);
            if (!$label || !$value) continue;

            $l = mb_strtolower($label);

            if (str_contains($l, 'ტიპი')) {
                $meta['decision_type'] = mb_substr($value, 0, 100);
            } elseif (str_contains($l, 'ნომერი')) {
                $meta['case_number'] = mb_substr($value, 0, 100);
            } elseif (str_contains($l, 'კოლეგია') || str_contains($l, 'პლენუმი')) {
                // "I კოლეგია - სახელი1, სახელი2"
                if (preg_match('/^([^-]+)\s*-\s*(.+)$/su', $value, $m)) {
                    $meta['college'] = trim($m[1]);
                    $meta['judges']  = trim($m[2]);
                } else {
                    $meta['college'] = $value;
                }
            } elseif (str_contains($l, 'გამოქვეყნება')) {
                $meta['publication_date'] = $this->parseGeorgianDate($value, withTime: true);
            } elseif (str_contains($l, 'თარიღი') && !$meta['decision_date']) {
                $meta['decision_date'] = $this->parseGeorgianDate($value);
            } elseif (str_contains($l, 'მოპასუხე')) {
                $meta['respondent'] = mb_substr($value, 0, 500);
            } elseif (str_contains($l, 'შედეგი')) {
                $meta['result'] = mb_substr($value, 0, 500);
            }
        }

        return $meta;
    }

    // ── Title ─────────────────────────────────────────────────────────────────

    private function parseTitle(\DOMXPath $xpath, array $meta): string
    {
        // h1 inside printablePageContent that is NOT a section header
        $h1s = $xpath->query('//*[@id="printablePageContent"]//h1[not(contains(@class,"Sub"))]');
        foreach ($h1s as $h1) {
            $text = $this->clean($h1->textContent);
            if (mb_strlen($text) > 5) return $text;
        }

        // Page <title> tag
        $titleNode = $xpath->query('//title')->item(0);
        if ($titleNode) {
            $text = preg_replace('/\s*[|·—-].*$/u', '', $this->clean($titleNode->textContent));
            if (mb_strlen(trim($text)) > 5) return trim($text);
        }

        return $meta['case_number'] ?? '';
    }

    // ── Content ───────────────────────────────────────────────────────────────

    private function parseContent(\DOMXPath $xpath): string
    {
        $container = $xpath->query('//*[@id="printablePageContent"]')->item(0)
            ?? $xpath->query('//*[contains(@class,"legal-inner")]')->item(0);

        if (!$container) return '';

        $parts = [];
        $this->walkNode($xpath, $container, $parts);

        return $this->clean(implode("\n", array_filter($parts)));
    }

    private function walkNode(\DOMXPath $xpath, \DOMNode $node, array &$parts): void
    {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text = $this->clean($child->textContent);
                if (mb_strlen($text) > 1) $parts[] = $text;
                continue;
            }

            if ($child->nodeType !== XML_ELEMENT_NODE) continue;

            $tag   = strtolower($child->nodeName);
            $class = $child->getAttribute('class') ?? '';
            $id    = $child->getAttribute('id')    ?? '';

            if (in_array($tag, ['script', 'style', 'nav', 'noscript'])) continue;
            if (str_contains($class, 'legal-inner-table'))     continue; // metadata table
            if (str_contains($class, 'legal-acts-inner-side')) continue; // navigation sidebar
            if ($id === 'legal-act-dropdown-btn')              continue;

            // Section headers — mark them clearly for better chunking
            if (str_contains($class, 'Sub-Section-Leg-Acts') || str_contains($class, 'Sub2-Section-Leg-Acts') || str_contains($class, 'Sub3-Section-Leg-Acts')) {
                $text = $this->clean($child->textContent);
                if ($text) $parts[] = "\n\n=== {$text} ===";
                continue;
            }

            // Add paragraph breaks for block elements
            if (in_array($tag, ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'li', 'tr'])) {
                $parts[] = '';
            }

            $this->walkNode($xpath, $child, $parts);

            if (in_array($tag, ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'li'])) {
                $parts[] = '';
            }
        }
    }

    // ── Date parsing ──────────────────────────────────────────────────────────

    /**
     * "29 აპრილი 2026"        → "2026-04-29"
     * "29 აპრილი 2026 17:27"  → "2026-04-29 17:27:00"
     */
    private function parseGeorgianDate(string $value, bool $withTime = false): ?string
    {
        static $months = [
            'იანვარ'  => '01', 'თებერვ'  => '02', 'მარტ'    => '03',
            'აპრილ'  => '04', 'მაი'     => '05', 'ივნის'   => '06',
            'ივლის'  => '07', 'აგვისტ'  => '08', 'სექტემბ' => '09',
            'ოქტომბ' => '10', 'ნოემბ'   => '11', 'დეკემბ'  => '12',
        ];

        foreach ($months as $geo => $num) {
            if (!str_contains($value, $geo)) continue;
            if (!preg_match('/(\d{1,2})\s+\S+\s+(\d{4})(?:\s+(\d{1,2}):(\d{2}))?/', $value, $m)) continue;

            $date = sprintf('%04d-%02d-%02d', $m[2], $num, $m[1]);
            if ($withTime && isset($m[3])) {
                return $date . ' ' . sprintf('%02d', $m[3]) . ':' . $m[4] . ':00';
            }
            return $date;
        }

        return null;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function clean(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);
        return trim($text);
    }
}
