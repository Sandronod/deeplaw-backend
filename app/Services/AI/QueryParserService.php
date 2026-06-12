<?php

namespace App\Services\AI;

use App\DTOs\ParsedQuery;

/**
 * Rule-based structured filter extractor — no API calls.
 *
 * Extracts: case number, year range, court, category, judge, law name, article number
 * from both the original user question and the extracted search terms.
 */
class QueryParserService
{
    // Georgian court keyword → canonical label used in DB
    private const COURT_SIGNALS = [
        'უზენაესი'    => 'უზენაესი სასამართლო',
        'სააპელაციო'  => 'სააპელაციო',
        'საქალაქო'    => 'საქალაქო',
        'რაიონული'    => 'რაიონული',
        'supreme'     => 'Supreme Court',
        'appellate'   => 'Appellate',
    ];

    // Category keyword → canonical label
    private const CATEGORY_SIGNALS = [
        'ადმინისტრაციულ'  => 'ადმინისტრაციული',
        'სამოქალაქო'      => 'სამოქალაქო',
        'სისხლ'           => 'სისხლის სამართალი',
        'შრომ'            => 'შრომითი',
        'საგადასახადო'    => 'საგადასახადო',
        'საბიუჯეტო'       => 'საბიუჯეტო',
        'გარემოს'         => 'გარემო',
        'ინტელექტუალ'     => 'ინტელექტუალური საკუთრება',
        'civil'           => 'სამოქალაქო',
        'criminal'        => 'სისხლის სამართალი',
        'administrative'  => 'ადმინისტრაციული',
        'tax'             => 'საგადასახადო',
        'labour'          => 'შრომითი',
        'labor'           => 'შრომითი',
    ];

    // Law type anchors used to identify law name boundaries
    private const LAW_TYPE_ANCHORS = [
        'კოდექსი', 'კოდექსის', 'კოდექსში',
        'კანონი', 'კანონის', 'კანონში',
        'წესდება', 'წესდების',
        'დებულება', 'დებულების',
        'რეგლამენტი', 'რეგლამენტის',
    ];

    // ECHR / Convention article signals
    private const ECHR_SIGNALS = [
        'echr', 'hudoc', 'strasbourg', 'european court of human rights',
        'convention', 'ადამიანის უფლებათა ევროპული სასამართლო',
        'ადამიანის უფლებათა ევროპული კონვენცია',
        'სტრასბურგის', 'სტრასბურგ',
    ];

    // Convention article keyword → article code
    private const CONVENTION_ARTICLE_SIGNALS = [
        'article 6'  => '6',  'მე-6 მუხლი'   => '6',  'სამართლიანი სასამართლო' => '6',
        'article 8'  => '8',  'მე-8 მუხლი'   => '8',  'პირადი ცხოვრება'         => '8',
        'article 10' => '10', 'მე-10 მუხლი'  => '10', 'სიტყვის თავისუფლება'    => '10',
        'article 3'  => '3',  'მე-3 მუხლი'   => '3',  'წამება'                   => '3',
        'article 5'  => '5',  'მე-5 მუხლი'   => '5',  'პირადი ხელშეუხებლობა'   => '5',
        'პატიმრობა' => '5', 'დაკავება' => '5', 'აღკვეთის ღონისძიება' => '5',
        'article 7'  => '7',  'მე-7 მუხლი'   => '7',  'nulla poena'              => '7',
        'სასჯელის უკუძალა' => '7',
        'article 2'  => '2',  'მე-2 მუხლი'   => '2',  'სიცოცხლის უფლება'       => '2',
        'article 11' => '11', 'მე-11 მუხლი'  => '11', 'შეკრების თავისუფლება'   => '11',
        'article 14' => '14', 'მე-14 მუხლი'  => '14', 'დისკრიმინაცია'           => '14',
        'article 13' => '13', 'მე-13 მუხლი'  => '13',
        'p1-1'       => 'P1-1', 'protocol 1'  => 'P1-1', 'საკუთრების დაცვა'     => 'P1-1',
    ];

    // Georgia ECHR phrases
    private const GEORGIA_ECHR_SIGNALS = [
        'against georgia', 'საქართველოს წინააღმდეგ', 'georgia echr',
        'hudoc georgia', 'echr georgia', 'საქართველო სტრასბურგ',
    ];

    /**
     * @param  string  $raw    Original user question
     * @param  string  $terms  Extracted search terms (from QueryExtractorService)
     */
    public function parse(string $raw, string $terms): ParsedQuery
    {
        $lower = mb_strtolower($raw);

        return new ParsedQuery(
            raw:                    $raw,
            terms:                  $terms,
            caseNumber:             $this->extractCaseNumber($raw),
            yearFrom:               $this->extractYearFrom($lower),
            yearTo:                 $this->extractYearTo($lower),
            court:                  $this->extractCourt($lower),
            category:               $this->extractCategory($lower),
            judge:                  $this->extractJudge($raw),
            lawName:                $this->extractLawName($raw),
            articleNumber:          $this->extractArticleNumber($raw),
            echrArticle:            $this->extractEchrArticle($lower),
            echrTopic:              $this->extractEchrTopic($lower),
            echrApplicationNumber:  $this->extractEchrApplicationNumber($raw),
            echrOnly:               $this->isEchrOnly($lower),
            georgiaRelated:         $this->isGeorgiaEchr($lower),
        );
    }

    // ── Extractors ────────────────────────────────────────────────────────────

    private function extractCaseNumber(string $text): ?string
    {
        if (preg_match('/[ა-ჰA-Z]{1,4}[-\/]\d+(?:\s*\([^)]+\))?/u', $text, $m)) {
            return trim($m[0]);
        }
        return null;
    }

    private function extractYearFrom(string $lower): ?int
    {
        if (preg_match('/\b((?:19|20)\d{2})\s*[-–]\s*((?:19|20)\d{2})\b/', $lower, $m)) {
            return $this->validYear((int) $m[1]);
        }
        if (preg_match('/\b((?:19|20)\d{2})\s*წლ/u', $lower, $m)) {
            return $this->validYear((int) $m[1]);
        }
        if (preg_match('/\b((?:19|20)\d{2})\b/', $lower, $m)) {
            return $this->validYear((int) $m[1]);
        }
        return null;
    }

    private function extractYearTo(string $lower): ?int
    {
        if (preg_match('/\b(?:19|20)\d{2}\s*[-–]\s*(((?:19|20)\d{2}))\b/', $lower, $m)) {
            return $this->validYear((int) $m[1]);
        }
        return null;
    }

    private function extractCourt(string $lower): ?string
    {
        foreach (self::COURT_SIGNALS as $signal => $canonical) {
            if (str_contains($lower, $signal)) {
                return $canonical;
            }
        }
        return null;
    }

    private function extractCategory(string $lower): ?string
    {
        foreach (self::CATEGORY_SIGNALS as $signal => $canonical) {
            if (str_contains($lower, $signal)) {
                return $canonical;
            }
        }
        return null;
    }

    private function extractJudge(string $text): ?string
    {
        if (preg_match('/მოსამართლ[ეი]\s+([\p{Georgian}][\p{Georgian}\s]{2,30})/u', $text, $m)) {
            $candidate = trim($m[1]);
            if (mb_strlen($candidate) >= 4 && mb_strlen($candidate) <= 40) {
                return $candidate;
            }
        }
        if (preg_match('/\bjudge\s+([A-Z][a-zA-Z\s]{2,30})/i', $text, $m)) {
            return trim($m[1]);
        }
        return null;
    }

    /**
     * Extract law name from query.
     *
     * Strategy: find a LAW_TYPE_ANCHOR word, then take the N words before it
     * as the law name prefix, combine with anchor → canonical law name.
     *
     * Examples:
     *   "შრომის კოდექსის 37-ე მუხლი" → "შრომის კოდექსი"
     *   "ზოგადი ადმინისტრაციული კოდექსი" → "ზოგადი ადმინისტრაციული კოდექსი"
     *   "საქართველოს სამოქალაქო კოდექსი" → "საქართველოს სამოქალაქო კოდექსი"
     */
    private function extractLawName(string $raw): ?string
    {
        $lower = mb_strtolower(trim($raw));
        $words = preg_split('/\s+/u', $lower);

        foreach ($words as $i => $word) {
            // Find the base form of the anchor (strip genitive endings)
            $baseAnchor = null;
            foreach (self::LAW_TYPE_ANCHORS as $anchor) {
                if ($word === $anchor) {
                    // Normalize to nominative form
                    $baseAnchor = match ($anchor) {
                        'კოდექსის', 'კოდექსში' => 'კოდექსი',
                        'კანონის',  'კანონში'  => 'კანონი',
                        'წესდების'             => 'წესდება',
                        'დებულების'            => 'დებულება',
                        'რეგლამენტის'          => 'რეგლამენტი',
                        default                => $anchor,
                    };
                    break;
                }
            }

            if ($baseAnchor === null) {
                continue;
            }

            // Take up to 3 words before the anchor
            $prefixWords = array_slice($words, max(0, $i - 3), min($i, 3));

            // Strip functional prefix words that aren't part of the law name
            $skip = ['და', 'ან', 'მე', 'ამ', 'ამის', 'ამ', 'მისი', 'მათი'];
            $prefixWords = array_filter($prefixWords, fn($w) => !in_array($w, $skip));

            if (empty($prefixWords)) {
                return $baseAnchor;
            }

            $lawName = implode(' ', $prefixWords) . ' ' . $baseAnchor;
            return $this->toTitleCase($lawName);
        }

        return null;
    }

    /**
     * Extract article number from query.
     *
     * Patterns:
     *   "მუხლი 37"       → "37"
     *   "37-ე მუხლი"     → "37"
     *   "მე-37 მუხლი"    → "37"
     *   "37-ე მუხლის 1-ლი ნაწილი" → "37"
     */
    private function extractArticleNumber(string $raw): ?string
    {
        // "მუხლი N" — article number follows the word
        if (preg_match('/მუხლი\s+(\d+)/u', $raw, $m)) {
            return $m[1];
        }

        // "N-ე მუხლი" or "მე-N მუხლი"
        if (preg_match('/(?:მე-)?(\d+)[–\-]?[ეა]?\s*მუხლ/u', $raw, $m)) {
            return $m[1];
        }

        return null;
    }

    // ── ECHR Extractors ───────────────────────────────────────────────────────

    private function extractEchrArticle(string $lower): ?string
    {
        foreach (self::CONVENTION_ARTICLE_SIGNALS as $signal => $code) {
            if (str_contains($lower, $signal)) {
                return $code;
            }
        }

        // Pattern: "article N" where N is a number 1-60 or protocol references
        if (preg_match('/\barticle\s+(\d{1,2})\b/i', $lower, $m)) {
            return $m[1];
        }
        if (preg_match('/\bp(\d)-(\d)\b/i', $lower, $m)) {
            return "P{$m[1]}-{$m[2]}";
        }

        return null;
    }

    private function extractEchrTopic(string $lower): ?string
    {
        $topics = [
            'fair trial'           => 'fair trial',
            'freedom of expression'=> 'freedom of expression',
            'right to life'        => 'right to life',
            'torture'              => 'torture',
            'privacy'              => 'privacy',
            'detention'            => 'detention',
            'property'             => 'property',
            'discrimination'       => 'discrimination',
        ];

        foreach ($topics as $signal => $topic) {
            if (str_contains($lower, $signal)) {
                return $topic;
            }
        }

        return null;
    }

    private function extractEchrApplicationNumber(string $raw): ?string
    {
        // ECHR app.no format: "16812/11" or "No. 16812/11"
        if (preg_match('/\b(\d{4,6}\/\d{2,4})\b/', $raw, $m)) {
            return $m[1];
        }
        return null;
    }

    private function isEchrOnly(string $lower): bool
    {
        $onlyPhrases = [
            'echr only', 'echr cases', 'only echr', 'strasbourg cases',
            'hudoc only', 'european court only',
            'echr-ის პრაქტიკა', 'სტრასბურგის პრაქტიკა',
            'ადამიანის უფლებათა ევროპული სასამართლოს პრაქტიკა',
        ];

        foreach ($onlyPhrases as $phrase) {
            if (str_contains($lower, $phrase)) {
                return true;
            }
        }

        // Has ECHR signal but no domestic/law signals
        $hasEchr     = $this->matchesAny($lower, self::ECHR_SIGNALS);
        $hasDomestic = str_contains($lower, 'ქართულ') || str_contains($lower, 'საქართველოს სასამართლო');
        $hasLaw      = $this->matchesAny($lower, ['კოდექსი', 'კანონი', 'მუხლი']);

        return $hasEchr && !$hasDomestic && !$hasLaw;
    }

    private function isGeorgiaEchr(string $lower): bool
    {
        return $this->matchesAny($lower, self::GEORGIA_ECHR_SIGNALS);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function matchesAny(string $text, array $signals): bool
    {
        foreach ($signals as $signal) {
            if (str_contains($text, mb_strtolower($signal))) {
                return true;
            }
        }
        return false;
    }

    private function validYear(int $year): ?int
    {
        return ($year >= 1990 && $year <= (int) date('Y')) ? $year : null;
    }

    /**
     * Capitalize first letter of each word (Georgian-safe mb_ version).
     */
    private function toTitleCase(string $text): string
    {
        $words = preg_split('/\s+/u', $text);
        $result = array_map(fn($w) => mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1), $words);
        return implode(' ', $result);
    }
}
