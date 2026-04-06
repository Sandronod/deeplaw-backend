<?php

namespace App\Services\Legal;

use App\DTOs\ParsedQuery;
use App\DTOs\SourcePlan;

class KnowledgeSourceRouter
{
    /**
     * Keywords that signal the user is asking about legislation/statutes.
     */
    private const LAW_SIGNALS = [
        'კანონი',
        'კოდექსი',
        'მუხლი',
        'დადგენილება',
        'კანონმდებლობა',
        'ნორმა',
        'რეგულაცია',
        'სამოქალაქო კოდექსი',
        'ადმინისტრაციული საპროცესო',
        'საგადასახადო კოდექსი',
        'ზოგადი ადმინისტრაციული',
        'სისხლის სამართლის',
        'სამართლებრივი ნორმა',
        'კანონის მიხედვით',
        'კანონის თანახმად',
        'კანონდარღვევა',
    ];

    /**
     * ECHR / Strasbourg signals.
     */
    private const ECHR_SIGNALS = [
        'echr',
        'hudoc',
        'strasbourg',
        'european court of human rights',
        'convention',
        'ადამიანის უფლებათა ევროპული სასამართლო',
        'ადამიანის უფლებათა ევროპული კონვენცია',
        'სტრასბურგ',
        'article 6', 'article 8', 'article 10', 'article 3',
        'article 5', 'article 2', 'article 11', 'article 14',
        'fair trial', 'freedom of expression',
        'echr-ის', 'echr–ის',
    ];

    /**
     * Plan which knowledge sources to query.
     *
     * Accepts both legacy string and ParsedQuery.
     * Pass ParsedQuery when available — it carries pre-extracted ECHR hints.
     */
    public function plan(string|ParsedQuery $query): SourcePlan
    {
        if ($query instanceof ParsedQuery) {
            return $this->planFromParsed($query);
        }

        // Legacy: raw string
        $lower       = mb_strtolower($query);
        $useLaw      = $this->matchesAny($lower, self::LAW_SIGNALS);
        $useEchr     = $this->matchesAny($lower, self::ECHR_SIGNALS);
        $useDomestic = !$this->isPureLawTextLookup($lower) && !$this->isEchrOnly($lower, $useEchr, $useLaw);

        return new SourcePlan(
            useDomestic: $useDomestic,
            useLaw:      $useLaw,
            useEchr:     $useEchr,
        );
    }

    private function planFromParsed(ParsedQuery $parsed): SourcePlan
    {
        $lower   = mb_strtolower($parsed->raw);
        $useLaw  = $this->matchesAny($lower, self::LAW_SIGNALS) || $parsed->hasLawHint();
        $useEchr = $parsed->hasEchrHint() || $this->matchesAny($lower, self::ECHR_SIGNALS);

        // Pure ECHR query: skip domestic + law
        if ($parsed->echrOnly) {
            return new SourcePlan(useDomestic: false, useLaw: false, useEchr: true);
        }

        $useDomestic = !$this->isPureLawTextLookup($lower);

        return new SourcePlan(
            useDomestic: $useDomestic,
            useLaw:      $useLaw,
            useEchr:     $useEchr,
        );
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

    private function isPureLawTextLookup(string $lower): bool
    {
        $pureLawPhrases = [
            'მომეცი კანონის ტექსტი',
            'მიმეცი კანონის ტექსტი',
            'კანონის სრული ტექსტი',
            'მუხლის ტექსტი',
            'ჩამოჩამოწერე მუხლი',
        ];

        return $this->matchesAny($lower, $pureLawPhrases);
    }

    private function isEchrOnly(string $lower, bool $hasEchr, bool $hasLaw): bool
    {
        if (!$hasEchr || $hasLaw) return false;

        $domesticSignals = ['ქართულ', 'საქართველოს სასამართლო', 'უზენაესი'];
        return !$this->matchesAny($lower, $domesticSignals);
    }
}
