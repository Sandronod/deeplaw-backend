<?php

namespace App\DTOs;

readonly class ParsedQuery
{
    public function __construct(
        public string  $raw,
        public string  $terms,
        public ?string $caseNumber = null,
        public ?int    $yearFrom   = null,
        public ?int    $yearTo     = null,
        public ?string $court      = null,
        public ?string $category   = null,
        public ?string $judge      = null,
    ) {}

    public function hasFilters(): bool
    {
        return $this->caseNumber !== null
            || $this->yearFrom   !== null
            || $this->court      !== null
            || $this->category   !== null
            || $this->judge      !== null;
    }

    /**
     * Single effective year for DB queries that only support one year filter.
     * Prefers yearFrom; yearTo is only used in range queries.
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
            'case_number' => $this->caseNumber,
            'year_from'   => $this->yearFrom,
            'year_to'     => $this->yearTo,
            'court'       => $this->court,
            'category'    => $this->category,
            'judge'       => $this->judge,
        ], fn($v) => $v !== null);
    }
}
