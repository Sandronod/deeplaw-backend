<?php

namespace App\Services\AI;

class AnswerValidatorService
{
    private const ARTICLE_PATTERNS = [
        '/მუხლ(?:ი|ის|ით|ზე|ში|იდან|ად|ებს|ები)?\s*№?\s*(\d{1,4})(?:\.\d+)?(?!\s*(?:[-–]\s*)?(?:ე|ლი)?\s*(?:ნაწილ|პუნქტ|ქვეპუნქტ))/u',
        '/(\d{1,4})(?:-?ე|ე)?\s+მუხლ(?:ი|ის|ით|ზე|ში|იდან|ად|ებს|ები)?/u',
    ];

    private const LEGAL_NUMBER_PATTERN = '/(?<![\p{L}\p{N}])(\d+(?:[.,]\d+)?)\s*(?:[-–]\s*)?(დღ(?:ე|ის|ით|იდან|ეში|ეებს|იანი|იან)?|თვ(?:ე|ის|ით|ეში|იანი|იან)?|წელ(?:ი|ს|ით|ში|იწად|იწადი|იანი|იან)?|წლ(?:ის|ით|ამდე|იანი|იან)?|ლარ(?:ი|ის|ით)?|₾|%|პროცენტ(?:ი|ის|ით)?|კალენდარულ(?:ი|ად)?|სამუშაო|საათ(?:ი|ის|ში)?|კვირ(?:ა|ის|აში)?)/u';

    private const DOMESTIC_CASE_LAW_PHRASES = [
        'უზენაესი სასამართლ',
        'საკასაციო სასამართლ',
        'საკასაციო პალატ',
    ];

    private const GENERAL_CASE_LAW_PHRASES = [
        'სასამართლო პრაქტიკ',
        'პრაქტიკის მიხედვით',
        'პრაქტიკით',
        'სასამართლომ დაადგინა',
        'სასამართლომ განმარტა',
        'გადაწყვეტილების მიხედვით',
        'გადაწყვეტილებებში',
    ];

    /**
     * @param array<int, array<string, mixed>> $decisions
     * @param array<int, array<string, mixed>> $matsneResults
     * @param array<int, array<string, mixed>> $echrResults
     * @param array<int, array<string, mixed>> $extractedRules
     * @return array<string, mixed>
     */
    public function validate(
        string $answerText,
        array $decisions = [],
        array $matsneResults = [],
        array $echrResults = [],
        array $extractedRules = [],
    ): array {
        $flags = [];

        $answerArticles = $this->extractArticleNumbers($answerText);
        $sourceArticles = $this->extractSourceArticleNumbers($matsneResults, $extractedRules, $decisions, $echrResults);

        foreach (array_diff($answerArticles, $sourceArticles) as $articleNum) {
            $flags[] = $this->flag(
                'unsupported_article',
                'high',
                "პასუხში ნახსენებია მუხლი {$articleNum}, მაგრამ მოძიებულ ნორმებში ეს მუხლი არ ჩანს.",
                $articleNum,
            );
        }

        $answerNumberMentions = $this->extractLegalNumberMentions($answerText);
        $answerNumbers = $this->uniqueSorted(array_column($answerNumberMentions, 'number'));
        $sourceNumbers = $this->extractSourceLegalNumbers($decisions, $matsneResults, $echrResults, $extractedRules);

        foreach ($answerNumberMentions as $mention) {
            if (!in_array($mention['number'], $sourceNumbers, true)) {
                $flags[] = $this->flag(
                    'unsupported_number',
                    'medium',
                    "პასუხში ნახსენებია რიცხვითი წესი/ვადა {$mention['text']}, მაგრამ წყაროებში იგივე რიცხვი ვერ მოიძებნა.",
                    $mention['number'],
                    $mention['text'],
                );
            }
        }

        $caseLawFlag = $this->detectUnsupportedCaseLawClaim($answerText, $decisions, $echrResults);
        if ($caseLawFlag !== null) {
            $flags[] = $caseLawFlag;
        }

        $score = $this->score($flags);
        $verdict = $this->verdict($flags, $score);

        return [
            'verdict' => $verdict,
            'score' => $score,
            'flags' => $flags,
            'summary' => [
                'flags_count' => count($flags),
                'high_flags' => count(array_filter($flags, fn (array $f) => $f['severity'] === 'high')),
                'medium_flags' => count(array_filter($flags, fn (array $f) => $f['severity'] === 'medium')),
                'low_flags' => count(array_filter($flags, fn (array $f) => $f['severity'] === 'low')),
                'unsupported_articles_count' => count(array_filter($flags, fn (array $f) => $f['type'] === 'unsupported_article')),
                'unsupported_numbers_count' => count(array_filter($flags, fn (array $f) => $f['type'] === 'unsupported_number')),
                'unsupported_case_law_claim' => (bool) array_filter($flags, fn (array $f) => $f['type'] === 'unsupported_case_law_claim'),
            ],
            'checked' => [
                'answer_articles' => $answerArticles,
                'source_articles' => $sourceArticles,
                'answer_legal_numbers' => $answerNumbers,
                'source_legal_numbers' => $sourceNumbers,
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function extractArticleNumbers(string $text): array
    {
        $articles = [];

        foreach (self::ARTICLE_PATTERNS as $pattern) {
            preg_match_all($pattern, $text, $matches);
            foreach ($matches[1] ?? [] as $match) {
                $articles[] = (string) (int) $match;
            }
        }

        return $this->uniqueSorted($articles);
    }

    /**
     * @param array<int, array<string, mixed>> $matsneResults
     * @param array<int, array<string, mixed>> $extractedRules
     * @param array<int, array<string, mixed>> $decisions
     * @param array<int, array<string, mixed>> $echrResults
     * @return array<int, string>
     */
    private function extractSourceArticleNumbers(
        array $matsneResults,
        array $extractedRules,
        array $decisions,
        array $echrResults,
    ): array
    {
        $articles = [];

        foreach ($matsneResults as $result) {
            foreach (['_article_num', 'article_num'] as $key) {
                if (isset($result[$key]) && preg_match('/\d{1,4}/', (string) $result[$key], $match)) {
                    $articles[] = (string) (int) $match[0];
                }
            }

            $articles = array_merge(
                $articles,
                $this->extractArticleNumbers(($result['title'] ?? '') . "\n" . ($result['excerpt'] ?? '')),
            );
        }

        foreach ($extractedRules as $rule) {
            if (isset($rule['article_num']) && preg_match('/\d{1,4}/', (string) $rule['article_num'], $match)) {
                $articles[] = (string) (int) $match[0];
            }
        }

        $articles = array_merge(
            $articles,
            $this->extractArticleNumbers($this->joinFields($decisions, ['excerpt', 'full_text', 'content'])),
            $this->extractArticleNumbers($this->joinFields($echrResults, ['title', 'excerpt', 'summary', 'content'])),
        );

        return $this->uniqueSorted($articles);
    }

    /**
     * @return array<int, array{number: string, text: string}>
     */
    private function extractLegalNumberMentions(string $text): array
    {
        preg_match_all(self::LEGAL_NUMBER_PATTERN, $text, $matches, PREG_SET_ORDER);

        $mentions = [];
        foreach ($matches as $match) {
            $mentions[] = [
                'number' => $this->normalizeNumber($match[1]),
                'text' => trim($match[0]),
            ];
        }

        return $mentions;
    }

    /**
     * @param array<int, array<string, mixed>> $decisions
     * @param array<int, array<string, mixed>> $matsneResults
     * @param array<int, array<string, mixed>> $echrResults
     * @param array<int, array<string, mixed>> $extractedRules
     * @return array<int, string>
     */
    private function extractSourceLegalNumbers(
        array $decisions,
        array $matsneResults,
        array $echrResults,
        array $extractedRules,
    ): array {
        $sourceText = implode("\n", array_filter([
            $this->joinFields($matsneResults, ['title', 'excerpt', 'content', 'text']),
            $this->joinFields($decisions, ['excerpt', 'full_text', 'content']),
            $this->joinFields($echrResults, ['title', 'excerpt', 'summary', 'content']),
            $this->joinNestedStrings($extractedRules),
        ]));

        return $this->uniqueSorted(array_column($this->extractLegalNumberMentions($sourceText), 'number'));
    }

    /**
     * @param array<int, array<string, mixed>> $decisions
     * @param array<int, array<string, mixed>> $echrResults
     */
    private function detectUnsupportedCaseLawClaim(string $answerText, array $decisions, array $echrResults): ?array
    {
        $lower = mb_strtolower($answerText);
        if ($this->hasNegativeCaseLawStatement($lower)) {
            return null;
        }

        if ($this->containsAny($lower, self::DOMESTIC_CASE_LAW_PHRASES) && empty($decisions)) {
            return $this->flag(
                'unsupported_case_law_claim',
                'high',
                'პასუხში არის საქართველოს სასამართლო პრაქტიკაზე მითითება, მაგრამ მოძიებულ წყაროებში სასამართლო გადაწყვეტილება არ არის.',
            );
        }

        if ($this->containsAny($lower, self::GENERAL_CASE_LAW_PHRASES) && empty($decisions) && empty($echrResults)) {
            return $this->flag(
                'unsupported_case_law_claim',
                'high',
                'პასუხში არის სასამართლო პრაქტიკაზე მითითება, მაგრამ მოძიებულ წყაროებში საქმე/გადაწყვეტილება არ არის.',
            );
        }

        return null;
    }

    private function hasNegativeCaseLawStatement(string $lower): bool
    {
        return (bool) (
            preg_match('/(პრაქტიკ|გადაწყვეტილებ|საქმე|უზენაეს).{0,60}(ვერ|არ)\s+(მოიძებნ|არის|დასტურდ|გვაქვს|მომეპოვება)/u', $lower)
            || preg_match('/(ვერ|არ)\s+(მოიძებნ|არის|დასტურდ|გვაქვს|მომეპოვება).{0,60}(პრაქტიკ|გადაწყვეტილებ|საქმე|უზენაეს)/u', $lower)
        );
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<int, string> $fields
     */
    private function joinFields(array $items, array $fields): string
    {
        $parts = [];

        foreach ($items as $item) {
            foreach ($fields as $field) {
                if (isset($item[$field]) && is_scalar($item[$field])) {
                    $parts[] = (string) $item[$field];
                }
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private function joinNestedStrings(array $value): string
    {
        $parts = [];

        array_walk_recursive($value, function (mixed $item) use (&$parts): void {
            if (is_scalar($item)) {
                $parts[] = (string) $item;
            }
        });

        return implode("\n", $parts);
    }

    /**
     * @param array<int, string> $haystack
     */
    private function containsAny(string $text, array $haystack): bool
    {
        foreach ($haystack as $needle) {
            if (str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $values
     * @return array<int, string>
     */
    private function uniqueSorted(array $values): array
    {
        $values = array_values(array_unique(array_filter($values, fn (string $value) => $value !== '')));
        usort($values, fn (string $a, string $b) => (float) $a <=> (float) $b ?: strcmp($a, $b));

        return $values;
    }

    private function normalizeNumber(string $value): string
    {
        $normalized = str_replace(',', '.', $value);

        if (str_contains($normalized, '.')) {
            $normalized = rtrim(rtrim($normalized, '0'), '.');
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function flag(string $type, string $severity, string $message, ?string $value = null, ?string $snippet = null): array
    {
        return array_filter([
            'type' => $type,
            'severity' => $severity,
            'message' => $message,
            'value' => $value,
            'snippet' => $snippet,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param array<int, array<string, mixed>> $flags
     */
    private function score(array $flags): int
    {
        $deduct = [
            'high' => 30,
            'medium' => 15,
            'low' => 5,
        ];

        $score = 100;
        foreach ($flags as $flag) {
            $score -= $deduct[$flag['severity']] ?? 5;
        }

        return max(0, $score);
    }

    /**
     * @param array<int, array<string, mixed>> $flags
     */
    private function verdict(array $flags, int $score): string
    {
        $hasHighFlag = (bool) array_filter($flags, fn (array $flag) => $flag['severity'] === 'high');

        if ($hasHighFlag || $score < 60) {
            return 'fail';
        }

        if (!empty($flags) || $score < 85) {
            return 'warn';
        }

        return 'pass';
    }
}
