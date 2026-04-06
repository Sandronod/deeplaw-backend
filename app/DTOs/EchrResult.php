<?php

namespace App\DTOs;

readonly class EchrResult
{
    public function __construct(
        public int     $caseId,
        public string  $hudocItemId,
        public ?string $applicationNumber,
        public string  $title,
        public ?string $judgmentDate,       // "2019-07-07"
        public ?string $documentType,       // "GRANDCHAMBER", "CHAMBER", "DECISION"
        public ?int    $importance,         // 1, 2, 3, 4
        public array   $echrArticles,       // ["6", "8", "P1-1"]
        public string  $excerpt,
        public float   $similarity,
        public string  $sourceUrl,
    ) {}
}
