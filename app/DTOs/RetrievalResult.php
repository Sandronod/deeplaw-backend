<?php

namespace App\DTOs;

readonly class RetrievalResult
{
    public function __construct(
        public array $decisions,
        public array $matchedCaseIds,
        public array $matchedCaseNumbers,
        public array $relevanceScores,
        public int   $usedChunkCount,
        public int   $usedCaseCount,
        public int   $totalMetaFound,
    ) {}

    public static function empty(): self
    {
        return new self(
            decisions:           [],
            matchedCaseIds:      [],
            matchedCaseNumbers:  [],
            relevanceScores:     [],
            usedChunkCount:      0,
            usedCaseCount:       0,
            totalMetaFound:      0,
        );
    }

    public function isEmpty(): bool
    {
        return empty($this->decisions);
    }
}
