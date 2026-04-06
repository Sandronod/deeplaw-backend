<?php

namespace App\DTOs;

readonly class LawResult
{
    public function __construct(
        public int    $lawId,
        public int    $articleId,
        public string $title,
        public string $articleNum,
        public string $articleTitle,
        public string $excerpt,
        public float  $similarity,
        public string $sourceUrl,
    ) {}
}
