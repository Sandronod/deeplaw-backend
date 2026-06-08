<?php

namespace App\DTOs;

/**
 * LegalTriageService-ის გამოსვლა.
 *
 * ეს ობიექტი წყვეტს:
 *   - რომელი სფეროა (domains, caseType)
 *   - რომელი წყაროები გვჭირდება (needsNorms, needsCases …)
 *   - რა ვეძებოთ (searchQuery, temporalYear)
 *   - რა რეჟიმში ვპასუხობთ (intent, mode, isComplex)
 */
class TriageResult
{
    public function __construct(
        public readonly string    $intent,           // 'search' | 'chat'
        public readonly string    $mode,             // 'advise' | 'find' | 'explain' | 'advocate' | 'compare' | 'summarize' | 'chat'
        public readonly string    $caseType,         // 'civil' | 'criminal' | 'administrative' | 'any'
        public readonly array     $domains,          // ['labor', 'civil', …]
        public readonly IssueList $issueList,
        public readonly string    $searchQuery,      // extracted search terms for embedding + ILIKE
        public readonly bool      $needsNorms,       // matsne retriever
        public readonly bool      $needsCases,       // court decisions retriever
        public readonly bool      $needsConstCourt,  // constitutional court retriever
        public readonly bool      $needsEu,          // EU law retriever
        public readonly bool      $needsGerman,      // German law retriever
        public readonly ?int      $temporalYear,     // year filter (null = no filter)
        public readonly bool      $isComplex,        // multiple domains or many issues
    ) {}

    public static function chat(): self
    {
        return new self(
            intent:          'chat',
            mode:            'chat',
            caseType:        'any',
            domains:         [],
            issueList:       IssueList::empty(),
            searchQuery:     '',
            needsNorms:      false,
            needsCases:      false,
            needsConstCourt: false,
            needsEu:         false,
            needsGerman:     false,
            temporalYear:    null,
            isComplex:       false,
        );
    }

    public function isChatOnly(): bool
    {
        return $this->intent === 'chat';
    }

    public function caseTypeFilter(): ?string
    {
        return $this->caseType === 'any' ? null : $this->caseType;
    }

    public function toDebugArray(): array
    {
        return [
            'intent'           => $this->intent,
            'mode'             => $this->mode,
            'case_type'        => $this->caseType,
            'domains'          => $this->domains,
            'search_query'     => mb_substr($this->searchQuery, 0, 80),
            'needs_norms'      => $this->needsNorms,
            'needs_cases'      => $this->needsCases,
            'needs_const_court'=> $this->needsConstCourt,
            'needs_eu'         => $this->needsEu,
            'needs_german'     => $this->needsGerman,
            'temporal_year'    => $this->temporalYear,
            'is_complex'       => $this->isComplex,
            'issue_count'      => $this->issueList->issueCount,
        ];
    }
}
