<?php

namespace App\DTOs;

readonly class ParsedQuery
{
    public function __construct(
        public string  $raw,
        public string  $terms,
        public ?string $caseNumber           = null,
        public ?int    $yearFrom             = null,
        public ?int    $yearTo               = null,
        public ?string $court                = null,
        public ?string $category             = null,
        public ?string $judge                = null,
        public ?string $lawName              = null,   // e.g. "შრომის კოდექსი"
        public ?string $articleNumber        = null,   // e.g. "37"
        // ── ECHR fields ──────────────────────────────────────────────────────
        public ?string $echrArticle          = null,   // Convention article, e.g. "6", "8", "P1-1"
        public ?string $echrTopic            = null,   // detected topic keyword, e.g. "fair trial"
        public ?string $echrApplicationNumber = null,  // specific app.no, e.g. "16812/11"
        public bool    $echrOnly             = false,  // query asks exclusively for ECHR
        public bool    $georgiaRelated       = false,  // query mentions Georgia + ECHR
    ) {}

    public function hasFilters(): bool
    {
        return $this->caseNumber    !== null
            || $this->yearFrom      !== null
            || $this->court         !== null
            || $this->category      !== null
            || $this->judge         !== null;
    }

    public function hasLawHint(): bool
    {
        return $this->lawName !== null || $this->articleNumber !== null;
    }

    public function hasEchrHint(): bool
    {
        return $this->echrArticle          !== null
            || $this->echrTopic            !== null
            || $this->echrApplicationNumber !== null
            || $this->echrOnly
            || $this->georgiaRelated;
    }

    /**
     * Single effective year for DB queries that only support one year filter.
     */
    public function effectiveYear(): ?int
    {
        return $this->yearFrom;
    }

    public function hasCaseNumber(): bool
    {
        return $this->caseNumber !== null;
    }

    public function toArray(): array
    {
        return array_filter([
            'case_number'            => $this->caseNumber,
            'year_from'              => $this->yearFrom,
            'year_to'                => $this->yearTo,
            'court'                  => $this->court,
            'category'               => $this->category,
            'judge'                  => $this->judge,
            'law_name'               => $this->lawName,
            'article_number'         => $this->articleNumber,
            'echr_article'           => $this->echrArticle,
            'echr_topic'             => $this->echrTopic,
            'echr_application_number'=> $this->echrApplicationNumber,
            'echr_only'              => $this->echrOnly  ?: null,
            'georgia_related'        => $this->georgiaRelated ?: null,
        ], fn($v) => $v !== null);
    }
}
