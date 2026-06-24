<?php

namespace App\DTOs;

readonly class SourcePlan
{
    public function __construct(
        public bool $useDomestic,
        public bool $useLaw,
        public bool $useEchr = false,
        public bool $useEu = false,
        public bool $useGerman = false,
        public bool $useConstCourt = false,
    ) {}

    public function isHybrid(): bool
    {
        return $this->useDomestic && $this->useLaw;
    }

    public function isFullHybrid(): bool
    {
        return $this->useDomestic && $this->useLaw && $this->useEchr;
    }

    public function sourcesActive(): array
    {
        return array_values(array_filter([
            $this->useDomestic ? 'domestic' : null,
            $this->useLaw      ? 'law'      : null,
            $this->useEchr     ? 'echr'     : null,
            $this->useEu       ? 'eu'       : null,
            $this->useGerman   ? 'german'   : null,
            $this->useConstCourt ? 'const_court' : null,
        ]));
    }
}
