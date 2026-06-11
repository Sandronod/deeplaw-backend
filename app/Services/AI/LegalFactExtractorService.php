<?php

namespace App\Services\AI;

/**
 * Cheap deterministic fact extraction for legal rule atoms.
 *
 * Keep this conservative: extract only facts we can identify with high
 * confidence, then let rule atoms decide what legal consequence follows.
 */
class LegalFactExtractorService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function extract(string $text): array
    {
        return $this->dedupeFacts([
            ...$this->extractMoneyFacts($text),
            ...$this->extractDeadlineFacts($text),
            ...$this->extractProceduralStageFacts($text),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function extractMoneyFacts(string $text): array
    {
        preg_match_all(
            '/(?<![\p{L}\p{N}])((?:\d{1,3}(?:(?:\s|\x{00A0})\d{3})+|\d+)(?:[.,]\d+)?)(?![\p{L}\p{N}])/u',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        $facts = [];
        $seen = [];

        foreach ($matches[1] ?? [] as $match) {
            [$raw, $offset] = $match;
            $value = (int) $this->normalizeNumber($raw);
            if ($value <= 0) {
                continue;
            }

            $sentence = $this->sentenceAroundByteOffset($text, $offset);
            $nearCurrency = mb_strtolower($this->byteWindow($text, $offset, 70));
            if (!$this->containsAny($nearCurrency, ['ლარ', '₾'])) {
                continue;
            }

            $context = mb_strtolower($sentence . "\n" . $this->byteWindow($text, max(0, $offset - 180), 320));
            if (!$this->containsAny($context, ['სარჩელ', 'მოთხოვნ', 'განსჯად', 'მაგისტრატ', 'ფას'])) {
                continue;
            }

            [$key, $label] = $this->claimValueFactIdentity($text, $sentence, $offset);
            $dedupeKey = "{$key}:{$value}:{$offset}";

            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $facts[] = [
                'type' => 'money',
                'key' => $key,
                'label' => $label,
                'value' => $value,
                'text' => trim($raw),
                'sentence' => $sentence,
            ];
            $seen[$dedupeKey] = true;
        }

        return $facts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function extractDeadlineFacts(string $text): array
    {
        preg_match_all(
            '/(?<![\p{L}\p{N}])(\d{1,4})(?:\s|\x{00A0})*(?:-|–)?\s*(დღ(?:ე|ის|ეში|იანი)?|თვ(?:ე|ის|ეში|იანი)?|წ(?:ელი|ლის|ელში|ლიანი)|კვირ(?:ა|ის|აში)?|საათ(?:ი|ის|ში)?)(?![\p{L}\p{N}])/u',
            $text,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        $facts = [];

        foreach ($matches[1] ?? [] as $index => $numberMatch) {
            [$rawNumber, $offset] = $numberMatch;
            $value = (int) $rawNumber;
            if ($value <= 0) {
                continue;
            }

            $unitText = (string) ($matches[2][$index][0] ?? '');
            $sentence = $this->sentenceAroundByteOffset($text, $offset);
            $context = mb_strtolower($this->byteWindow($text, max(0, $offset - 80), 180));

            [$key, $label] = $this->deadlineFactIdentity($context);
            $facts[] = [
                'type' => 'deadline',
                'key' => $key,
                'label' => $label,
                'value' => $value,
                'unit' => $this->normalizeDeadlineUnit($unitText),
                'text' => trim($rawNumber . ' ' . $unitText),
                'sentence' => $sentence,
            ];
        }

        return $facts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function extractProceduralStageFacts(string $text): array
    {
        $lower = mb_strtolower($text);
        $patterns = [
            'preparatory_hearing' => [
                'label' => 'მოსამზადებელი სხდომა',
                'needles' => ['მოსამზადებელ სხდომ', 'მოსამზადებელი სხდომ'],
            ],
            'main_hearing' => [
                'label' => 'მთავარი სხდომა',
                'needles' => ['მთავარ სხდომ', 'მთავარი სხდომ'],
            ],
            'oral_hearing' => [
                'label' => 'ზეპირი განხილვა',
                'needles' => ['ზეპირ განხილ', 'ზეპირი განხილ'],
            ],
            'claim_filing' => [
                'label' => 'სარჩელის შეტანა',
                'needles' => ['სარჩელ', 'შეტან'],
            ],
            'appeal_stage' => [
                'label' => 'გასაჩივრების ეტაპი',
                'needles' => ['გასაჩივრ', 'სააპელაციო', 'საკასაციო'],
            ],
        ];

        $facts = [];
        foreach ($patterns as $stage => $definition) {
            $needles = $definition['needles'];
            $matched = $stage === 'claim_filing'
                ? $this->containsAll($lower, $needles)
                : $this->containsAny($lower, $needles);

            if (!$matched) {
                continue;
            }

            $facts[] = [
                'type' => 'procedural_stage',
                'key' => 'procedural_stage',
                'label' => 'საპროცესო ეტაპი',
                'value' => $stage,
                'text' => $definition['label'],
                'sentence' => $definition['label'],
            ];
        }

        return $facts;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function claimValueFactIdentity(string $text, string $sentence, int $offset): array
    {
        $prefixStart = max(0, $offset - 220);
        $prefix = mb_strtolower($this->byteWindow($text, $prefixStart, $offset - $prefixStart));
        $localSentencePrefix = mb_strtolower($this->sentencePrefixBeforeOffset($text, $sentence, $offset));

        $counterPos = max(
            $this->lastKeywordPosition($localSentencePrefix, ['შეგებებულ', 'შემხვედრ']),
            $this->lastKeywordPosition($prefix, ['შეგებებულ', 'შემხვედრ']),
        );
        $mainPos = max(
            $this->lastKeywordPosition($localSentencePrefix, ['ძირითად', 'ძირითადი', 'პირვანდელ', 'პირვანდელი']),
            $this->lastKeywordPosition($prefix, ['ძირითად', 'ძირითადი', 'პირვანდელ', 'პირვანდელი']),
        );

        if ($counterPos >= 0 && $counterPos >= $mainPos) {
            return ['counterclaim_value', 'შეგებებული სარჩელის ფასი'];
        }

        if ($mainPos >= 0) {
            return ['main_claim_value', 'ძირითადი სარჩელის ფასი'];
        }

        return ['claim_value', 'სარჩელის ფასი'];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function deadlineFactIdentity(string $context): array
    {
        if ($this->containsAny($context, ['გასაჩივრ', 'საჩივრ', 'სააპელაციო', 'აპელაცი', 'საკასაციო', 'კასაცი'])) {
            return ['appeal_deadline', 'გასაჩივრების ვადა'];
        }

        if ($this->containsAny($context, ['ხანდაზმ'])) {
            return ['limitation_period', 'ხანდაზმულობის ვადა'];
        }

        if ($this->containsAny($context, ['შესაგებელ', 'შეპასუხ', 'პასუხის წარდგენ'])) {
            return ['response_deadline', 'პასუხის / შესაგებლის ვადა'];
        }

        if ($this->containsAny($context, ['შეტყობინ', 'აცნობ', 'პრეტენზ'])) {
            return ['notice_deadline', 'შეტყობინების / პრეტენზიის ვადა'];
        }

        if ($this->containsAny($context, ['შესრულ', 'გადახდ'])) {
            return ['performance_deadline', 'შესრულების ვადა'];
        }

        return ['procedural_deadline', 'საპროცესო ვადა'];
    }

    private function normalizeDeadlineUnit(string $unit): string
    {
        return match (true) {
            str_starts_with($unit, 'დღ') => 'day',
            str_starts_with($unit, 'თვ') => 'month',
            str_starts_with($unit, 'წ') => 'year',
            str_starts_with($unit, 'კვირ') => 'week',
            str_starts_with($unit, 'საათ') => 'hour',
            default => 'unknown',
        };
    }

    private function sentencePrefixBeforeOffset(string $text, string $sentence, int $offset): string
    {
        $sentenceStart = strpos($text, $sentence);
        if ($sentenceStart === false || $offset <= $sentenceStart) {
            return '';
        }

        return mb_strcut($text, $sentenceStart, $offset - $sentenceStart, 'UTF-8') ?: '';
    }

    /**
     * @param array<int, string> $keywords
     */
    private function lastKeywordPosition(string $text, array $keywords): int
    {
        $last = -1;
        foreach ($keywords as $keyword) {
            $pos = mb_strripos($text, $keyword);
            if ($pos !== false) {
                $last = max($last, $pos);
            }
        }

        return $last;
    }

    private function byteWindow(string $text, int $start, int $length): string
    {
        return mb_strcut($text, $start, $length, 'UTF-8') ?: '';
    }

    private function sentenceAroundByteOffset(string $text, int $offset): string
    {
        $prefix = substr($text, 0, $offset) ?: '';
        $start = 0;
        foreach ([".", "?", "!", "\n"] as $delimiter) {
            $pos = strrpos($prefix, $delimiter);
            if ($pos !== false) {
                $start = max($start, $pos + strlen($delimiter));
            }
        }

        $suffix = substr($text, $offset) ?: '';
        $end = strlen($text);
        foreach ([".", "?", "!", "\n"] as $delimiter) {
            $pos = strpos($suffix, $delimiter);
            if ($pos !== false) {
                $end = min($end, $offset + $pos);
            }
        }

        return trim(mb_strcut($text, $start, max(0, $end - $start), 'UTF-8') ?: '');
    }

    private function normalizeNumber(string $value): string
    {
        $normalized = preg_replace('/(?:\s|\x{00A0})+/u', '', $value) ?? $value;
        $normalized = str_replace(',', '.', $normalized);

        if (str_contains($normalized, '.')) {
            $normalized = rtrim(rtrim($normalized, '0'), '.');
        }

        return $normalized;
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     * @return array<int, array<string, mixed>>
     */
    private function dedupeFacts(array $facts): array
    {
        $seen = [];
        $deduped = [];

        foreach ($facts as $fact) {
            $key = implode(':', [
                (string) ($fact['type'] ?? ''),
                (string) ($fact['key'] ?? ''),
                (string) ($fact['value'] ?? ''),
                (string) ($fact['unit'] ?? ''),
                (string) ($fact['text'] ?? ''),
            ]);

            if (isset($seen[$key])) {
                continue;
            }

            $deduped[] = $fact;
            $seen[$key] = true;
        }

        return $deduped;
    }

    /**
     * @param array<int, string> $needles
     */
    private function containsAny(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $needles
     */
    private function containsAll(string $text, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (!str_contains($text, $needle)) {
                return false;
            }
        }

        return true;
    }
}
