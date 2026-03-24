<?php

namespace App\Services\AI;

use App\DTOs\ParsedQuery;

/**
 * Rule-based structured filter extractor — no API calls.
 *
 * Extracts: case number, year range, court, category, judge
 * from both the original user question and the extracted search terms.
 *
 * Works in parallel with QueryExtractorService (which handles term distillation).
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

    /**
     * @param  string  $raw    Original user question
     * @param  string  $terms  Extracted search terms (from QueryExtractorService)
     */
    public function parse(string $raw, string $terms): ParsedQuery
    {
        $lower = mb_strtolower($raw);

        return new ParsedQuery(
            raw:        $raw,
            terms:      $terms,
            caseNumber: $this->extractCaseNumber($raw),
            yearFrom:   $this->extractYearFrom($lower),
            yearTo:     $this->extractYearTo($lower),
            court:      $this->extractCourt($lower),
            category:   $this->extractCategory($lower),
            judge:      $this->extractJudge($raw),
        );
    }

    // ── Extractors ────────────────────────────────────────────────────────────

    private function extractCaseNumber(string $text): ?string
    {
        // Georgian case number pattern: ბს-123, ბს-123(კ-22), ა/123-22 etc.
        if (preg_match('/[ა-ჰA-Z]{1,4}[-\/]\d+(?:\s*\([^)]+\))?/u', $text, $m)) {
            return trim($m[0]);
        }
        return null;
    }

    private function extractYearFrom(string $lower): ?int
    {
        // Range: "2018-2022" or "2018 – 2022"
        if (preg_match('/\b((?:19|20)\d{2})\s*[-–]\s*((?:19|20)\d{2})\b/', $lower, $m)) {
            return $this->validYear((int) $m[1]);
        }

        // "2020 წლიდან" or "2020 წელს"
        if (preg_match('/\b((?:19|20)\d{2})\s*წლ/u', $lower, $m)) {
            return $this->validYear((int) $m[1]);
        }

        // Bare year (single)
        if (preg_match('/\b((?:19|20)\d{2})\b/', $lower, $m)) {
            return $this->validYear((int) $m[1]);
        }

        return null;
    }

    private function extractYearTo(string $lower): ?int
    {
        // Range end only
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
        // "მოსამართლე სახელი გვარი" — Georgian
        if (preg_match('/მოსამართლ[ეი]\s+([\p{Georgian}][\p{Georgian}\s]{2,30})/u', $text, $m)) {
            $candidate = trim($m[1]);
            // Avoid matching generic words
            if (mb_strlen($candidate) >= 4 && mb_strlen($candidate) <= 40) {
                return $candidate;
            }
        }

        // "judge Name Surname" — Latin
        if (preg_match('/\bjudge\s+([A-Z][a-zA-Z\s]{2,30})/i', $text, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function validYear(int $year): ?int
    {
        return ($year >= 1990 && $year <= (int) date('Y')) ? $year : null;
    }
}
