<?php

namespace App\DTOs;

readonly class ConfidenceResult
{
    public function __construct(
        public float  $score,
        public string $label,
        public string $explanation,
    ) {}

    public function isReliable(): bool
    {
        return in_array($this->label, ['high', 'medium']);
    }

    /**
     * Returns label for use in system prompt / logging.
     */
    public function humanLabel(): string
    {
        return match ($this->label) {
            'high'   => 'მაღალი სანდოობა',
            'medium' => 'საშუალო სანდოობა',
            'low'    => 'დაბალი სანდოობა',
            'none'   => 'შედეგი არ მოიძებნა',
            default  => 'უცნობი',
        };
    }
}
