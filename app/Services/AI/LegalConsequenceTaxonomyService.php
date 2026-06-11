<?php

namespace App\Services\AI;

use App\DTOs\TriageResult;

/**
 * Applies deterministic legal-consequence rule atoms.
 *
 * The registry defines legal rule atoms; this service extracts facts, evaluates
 * configured operators, and builds small guard blocks for the LLM.
 */
class LegalConsequenceTaxonomyService
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $ruleAtoms;

    private LegalFactExtractorService $factExtractor;

    /**
     * @param array<string, array<string, mixed>>|null $ruleAtoms
     */
    public function __construct(
        ?array $ruleAtoms = null,
        ?LegalFactExtractorService $factExtractor = null,
    ) {
        $this->ruleAtoms = $ruleAtoms ?? $this->loadRuleAtoms();
        $this->factExtractor = $factExtractor ?? new LegalFactExtractorService();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function applyRuleAtoms(string $question, ?TriageResult $triage = null): array
    {
        $facts = $this->factExtractor->extract($question);
        $lower = mb_strtolower($question);
        $applied = [];

        foreach ($this->ruleAtoms as $key => $rule) {
            if (!$this->questionMatchesRule($lower, $rule, $triage)) {
                continue;
            }

            if ($this->isThresholdRule($rule)) {
                foreach ($this->matchingFacts($facts, $rule) as $fact) {
                    $applied[] = $this->applyThresholdRule($key, $rule, $fact);
                }

                continue;
            }

            $applied[] = $this->applyTriggeredRule($key, $rule);
        }

        return $this->dedupeAppliedRules($applied);
    }

    public function buildPromptBlock(string $question, ?TriageResult $triage = null): string
    {
        $appliedRules = $this->applyRuleAtoms($question, $triage);
        if (empty($appliedRules)) {
            return '';
        }

        $lines = [
            '⚙️ LEGAL CONSEQUENCE RULE ATOMS (deterministic)',
            'ქვემოთ მოცემული შედეგები გამოთვლილია სტრუქტურული rule atom-ებით. პასუხში არ შეცვალო მათი ზღვრული ლოგიკა.',
        ];

        foreach ($appliedRules as $rule) {
            if (isset($rule['fact_value'])) {
                $lines[] = sprintf(
                    '• %s (%s): condition `%s`; boundary `%s`; %s = %s GEL → %s → `%s`.',
                    $rule['key'],
                    $rule['article'],
                    $rule['condition'],
                    $rule['boundary_rule'],
                    $rule['fact_label'],
                    $this->formatNumber((int) $rule['fact_value']),
                    $rule['reason'],
                    $rule['outcome'],
                );
            } else {
                $lines[] = sprintf(
                    '• %s (%s): `%s` → `%s`; boundary `%s`.',
                    $rule['key'],
                    $rule['article'],
                    $rule['condition'],
                    $rule['outcome'],
                    $rule['boundary_rule'],
                );
            }

            $lines[] = "  Guard: {$rule['instruction']}";
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<int, string>
     */
    public function summaryLines(string $ruleKey): array
    {
        $rule = $this->ruleAtoms[$ruleKey] ?? null;
        if (!is_array($rule)) {
            return [];
        }

        return $this->configuredLines($rule, 'summary_lines');
    }

    /**
     * @return array<int, string>
     */
    public function promptGuidanceLines(string $ruleKey): array
    {
        $rule = $this->ruleAtoms[$ruleKey] ?? null;
        if (!is_array($rule)) {
            return [];
        }

        return array_merge(
            $this->configuredLines($rule, 'summary_lines'),
            $this->configuredLines($rule, 'prompt_guard_lines'),
        );
    }

    /**
     * @return array<int, string>
     */
    public function promptGuidanceLinesForQuestion(
        string $question,
        ?TriageResult $triage = null,
        ?string $categoryPrefix = null,
    ): array {
        $lowerQuestion = mb_strtolower($question);
        $lines = [];
        $seen = [];

        foreach ($this->ruleAtoms as $key => $rule) {
            if ($categoryPrefix !== null
                && !str_starts_with((string) ($rule['category'] ?? ''), $categoryPrefix)
            ) {
                continue;
            }

            if (!$this->questionMatchesRule($lowerQuestion, $rule, $triage)) {
                continue;
            }

            foreach ($this->promptGuidanceLines($key) as $line) {
                if (isset($seen[$line])) {
                    continue;
                }

                $lines[] = $line;
                $seen[$line] = true;
            }
        }

        return $lines;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function boundaryFindings(string $answerText): array
    {
        $findings = [];

        foreach ($this->ruleAtoms as $key => $rule) {
            if (!$this->isThresholdRule($rule) || ($rule['boundary_rule'] ?? null) !== 'equal_included') {
                continue;
            }

            $threshold = (int) $rule['threshold'];
            $wrongSentences = $this->wrongBoundarySentences($answerText, $threshold, $rule);
            $correctSentences = $this->correctBoundarySentences($answerText, $threshold, $rule);

            if (!empty($wrongSentences)) {
                $findings[] = [
                    'type' => 'wrong_threshold_boundary',
                    'severity' => 'high',
                    'message' => sprintf(
                        'პასუხი ზუსტად %s ლარს შესაბამის ზღვარს გარეთ აყენებს, მაშინ როცა rule atom არის %s და boundary_rule არის %s.',
                        $this->formatNumber($threshold),
                        $rule['condition'] ?? "{$key} threshold rule",
                        $rule['boundary_rule'],
                    ),
                    'value' => $key,
                    'snippet' => mb_substr($wrongSentences[0], 0, 220),
                ];
            }

            if (!empty($wrongSentences) && !empty($correctSentences)) {
                $findings[] = [
                    'type' => 'contradictory_boundary_application',
                    'severity' => 'high',
                    'message' => sprintf('პასუხი ერთდროულად ამბობს, რომ ზუსტად %s ლარი ზღვარში შედის და არ შედის.', $this->formatNumber($threshold)),
                    'value' => (string) $threshold,
                    'snippet' => mb_substr($wrongSentences[0] . ' / ' . $correctSentences[0], 0, 260),
                ];
            }
        }

        return $findings;
    }

    /**
     * Backward-compatible alias for existing validator integration/tests.
     *
     * @return array<int, array<string, string>>
     */
    public function magistrateBoundaryFindings(string $answerText): array
    {
        return $this->boundaryFindings($answerText);
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function questionMatchesRule(string $lowerQuestion, array $rule, ?TriageResult $triage): bool
    {
        $domainTriggers = $rule['trigger_domains'] ?? [];
        if (is_array($domainTriggers) && !empty($domainTriggers)) {
            $domains = $triage?->domains ?? [];
            if (empty(array_intersect($domainTriggers, $domains))) {
                return false;
            }
        }

        $caseTypeTriggers = $rule['trigger_case_types'] ?? [];
        if (is_array($caseTypeTriggers) && !empty($caseTypeTriggers)) {
            if (!in_array($triage?->caseType, $caseTypeTriggers, true)) {
                return false;
            }
        }

        $any = $rule['trigger_any_keywords'] ?? [];
        if (is_array($any) && !empty($any) && !$this->containsAny($lowerQuestion, $any)) {
            return false;
        }

        $groups = $rule['trigger_all_keywords'] ?? [];
        if (is_array($groups) && !empty($groups)) {
            foreach ($groups as $group) {
                if (!is_array($group) || !$this->containsAny($lowerQuestion, $group)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function isThresholdRule(array $rule): bool
    {
        return isset($rule['operator'], $rule['threshold'])
            && is_numeric($rule['threshold']);
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     * @param array<string, mixed> $rule
     * @return array<int, array<string, mixed>>
     */
    private function matchingFacts(array $facts, array $rule): array
    {
        $keys = $rule['fact_keys'] ?? [$rule['fact_key'] ?? null];
        $keys = array_values(array_filter(is_array($keys) ? $keys : [$keys], 'is_string'));

        return array_values(array_filter(
            $facts,
            fn (array $fact) => in_array($fact['key'] ?? null, $keys, true)
        ));
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $fact
     * @return array<string, mixed>
     */
    private function applyThresholdRule(string $key, array $rule, array $fact): array
    {
        $value = (int) $fact['value'];
        $threshold = (int) $rule['threshold'];
        $operator = (string) $rule['operator'];
        $applies = $this->compare($value, $operator, $threshold);
        $reasonOperator = $applies ? $operator : $this->falseOperator($operator);

        return [
            'key' => $key,
            'article' => $rule['article'] ?? '',
            'condition' => $rule['condition'] ?? "{$fact['key']} {$operator} {$threshold}",
            'category' => $rule['category'] ?? 'legal_consequence',
            'fact_key' => $fact['key'],
            'fact_label' => $fact['label'],
            'fact_value' => $value,
            'fact_text' => $fact['text'],
            'outcome' => $applies ? ($rule['outcome_true'] ?? '') : ($rule['outcome_false'] ?? ''),
            'boundary_rule' => $rule['boundary_rule'] ?? '',
            'applies' => $applies,
            'reason' => sprintf('%s %s %s', $this->formatNumber($value), $reasonOperator, $this->formatNumber($threshold)),
            'instruction' => $this->instructionForThresholdRule($rule, $value, $threshold, $applies),
        ];
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<string, mixed>
     */
    private function applyTriggeredRule(string $key, array $rule): array
    {
        return [
            'key' => $key,
            'article' => $rule['article'] ?? '',
            'condition' => $rule['condition'] ?? $key,
            'category' => $rule['category'] ?? 'legal_consequence',
            'outcome' => $rule['outcome_true'] ?? '',
            'boundary_rule' => $rule['boundary_rule'] ?? '',
            'applies' => true,
            'reason' => $rule['reason'] ?? '',
            'instruction' => $this->renderTemplate((string) ($rule['prompt_true'] ?? ''), $rule),
        ];
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function instructionForThresholdRule(array $rule, int $value, int $threshold, bool $applies): string
    {
        $template = null;

        if ($applies && $value === $threshold && ($rule['boundary_rule'] ?? null) === 'equal_included') {
            $template = $rule['prompt_equal'] ?? null;
        }

        $template ??= $applies ? ($rule['prompt_true'] ?? null) : ($rule['prompt_false'] ?? null);
        if (!is_string($template) || $template === '') {
            return '';
        }

        return $this->renderTemplate($template, $rule, ['value' => $value]);
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<int, string>
     */
    private function configuredLines(array $rule, string $key): array
    {
        $lines = [];

        foreach (($rule[$key] ?? []) as $line) {
            if (is_string($line) && $line !== '') {
                $lines[] = $this->renderTemplate($line, $rule);
            }
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, int> $extra
     */
    private function renderTemplate(string $template, array $rule, array $extra = []): string
    {
        $threshold = isset($rule['threshold']) ? (int) $rule['threshold'] : null;
        $replacements = [
            '{article}' => (string) ($rule['article'] ?? ''),
            '{threshold}' => $threshold ? $this->formatNumber($threshold) : '',
            '{threshold_compact}' => $threshold ? (string) $threshold : '',
        ];

        foreach ($extra as $key => $value) {
            $replacements['{' . $key . '}'] = $this->formatNumber($value);
            $replacements['{' . $key . '_compact}'] = (string) $value;
        }

        return strtr($template, $replacements);
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @return array<int, array<string, mixed>>
     */
    private function dedupeAppliedRules(array $rules): array
    {
        $seen = [];
        $deduped = [];

        foreach ($rules as $rule) {
            $key = implode(':', [
                $rule['key'] ?? '',
                $rule['fact_key'] ?? '',
                (string) ($rule['fact_value'] ?? ''),
                $rule['outcome'] ?? '',
            ]);

            if (isset($seen[$key])) {
                continue;
            }

            $deduped[] = $rule;
            $seen[$key] = true;
        }

        return $deduped;
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<int, string>
     */
    private function wrongBoundarySentences(string $text, int $threshold, array $rule): array
    {
        $sentences = $this->sentences($text);
        $wrong = [];

        foreach ($sentences as $sentence) {
            $lower = mb_strtolower($sentence);
            if (!$this->mentionsThreshold($lower, $threshold) || $this->isMetaWarningSentence($lower)) {
                continue;
            }

            if ($this->strictlyGreaterThanThresholdOnly($lower, $threshold)) {
                continue;
            }

            $targetKeywords = $rule['validation_target_keywords'] ?? [];
            $wrongKeywords = $rule['validation_wrong_keywords'] ?? [];
            $hasTarget = is_array($targetKeywords) && $this->containsAny($lower, $targetKeywords);
            $hasWrong = is_array($wrongKeywords) && $this->containsAny($lower, $wrongKeywords);

            if ($hasTarget && $hasWrong) {
                $wrong[] = trim($sentence);
            }
        }

        return $wrong;
    }

    /**
     * @param array<string, mixed> $rule
     * @return array<int, string>
     */
    private function correctBoundarySentences(string $text, int $threshold, array $rule): array
    {
        $sentences = $this->sentences($text);
        $correct = [];

        foreach ($sentences as $sentence) {
            $lower = mb_strtolower($sentence);
            if (!$this->mentionsThreshold($lower, $threshold) || $this->isMetaWarningSentence($lower)) {
                continue;
            }

            $targetKeywords = $rule['validation_target_keywords'] ?? [];
            $correctKeywords = $rule['validation_correct_keywords'] ?? [];
            $exclusionKeywords = $rule['validation_exclusion_keywords'] ?? [];
            $includedBoundary = array_merge(
                ['≤', '<=', 'არ აღემატ', 'არ აჭარბ', 'ან ნაკლებ', 'ზუსტად'],
                array_map(fn (string $variant) => "{$variant} ლარია", $this->thresholdVariants($threshold)),
            );

            $hasTarget = is_array($targetKeywords) && $this->containsAny($lower, $targetKeywords);
            $hasIncludedBoundary = $this->containsAny($lower, $includedBoundary);
            $hasCorrect = is_array($correctKeywords) && $this->containsAny($lower, $correctKeywords);
            $hasExclusion = is_array($exclusionKeywords) && $this->containsAny($lower, $exclusionKeywords);

            if ($hasTarget && $hasIncludedBoundary && $hasCorrect && !$hasExclusion) {
                $correct[] = trim($sentence);
            }
        }

        return $correct;
    }

    /**
     * @return array<int, string>
     */
    private function sentences(string $text): array
    {
        $sentences = preg_split('/(?<=[.?!])\s+|\R+/u', $text) ?: [$text];

        return array_values(array_filter(array_map('trim', $sentences), fn (string $sentence) => $sentence !== ''));
    }

    private function mentionsThreshold(string $lower, int $threshold): bool
    {
        foreach ($this->thresholdRegexParts($threshold) as $part) {
            if (preg_match('/' . $part . '/u', $lower)) {
                return true;
            }
        }

        return false;
    }

    private function strictlyGreaterThanThresholdOnly(string $lower, int $threshold): bool
    {
        if (str_contains($lower, 'ზუსტად') || str_contains($lower, 'ან აღემატ')) {
            return false;
        }

        foreach ($this->thresholdVariants($threshold) as $variant) {
            if (str_contains($lower, '> ' . $variant)
                || str_contains($lower, $variant . '-ზე მეტ')
                || str_contains($lower, $variant . ' ლარზე მეტ')
            ) {
                return true;
            }
        }

        return str_contains($lower, 'აღემატ') && !str_contains($lower, 'არ აღემატ');
    }

    private function isMetaWarningSentence(string $lower): bool
    {
        return $this->containsAny($lower, ['არ უნდა ითქვას', 'ნუ დაწერ', 'არასწორ', 'wrong:', 'bad:', '❌']);
    }

    private function compare(int $value, string $operator, int $threshold): bool
    {
        return match ($operator) {
            '<' => $value < $threshold,
            '<=' => $value <= $threshold,
            '>' => $value > $threshold,
            '>=' => $value >= $threshold,
            '=' , '==' => $value === $threshold,
            default => false,
        };
    }

    private function falseOperator(string $operator): string
    {
        return match ($operator) {
            '<' => '>=',
            '<=' => '>',
            '>' => '<=',
            '>=' => '<',
            '=' , '==' => '!=',
            default => 'not ' . $operator,
        };
    }

    private function formatNumber(int $value): string
    {
        return number_format($value, 0, '.', ' ');
    }

    /**
     * @return array<int, string>
     */
    private function thresholdVariants(int $threshold): array
    {
        return array_values(array_unique([
            $this->formatNumber($threshold),
            (string) $threshold,
        ]));
    }

    /**
     * @return array<int, string>
     */
    private function thresholdRegexParts(int $threshold): array
    {
        $compact = (string) $threshold;
        $groups = [];
        while ($compact !== '') {
            array_unshift($groups, substr($compact, -3));
            $compact = substr($compact, 0, -3);
        }
        $spaced = implode('(?:\s|\x{00A0})*', $groups);
        $compact = (string) $threshold;

        return array_values(array_unique([
            preg_quote($this->formatNumber($threshold), '/'),
            preg_quote($compact, '/'),
            $spaced,
        ]));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadRuleAtoms(): array
    {
        if (function_exists('config')) {
            try {
                $rules = config('legal_consequence_rules.rules');
                if (is_array($rules) && !empty($rules)) {
                    return $rules;
                }
            } catch (\Throwable) {
                // Unit tests may instantiate this service outside the Laravel container.
            }
        }

        $configPath = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'legal_consequence_rules.php';
        if (is_file($configPath)) {
            $config = require $configPath;
            $rules = is_array($config) ? ($config['rules'] ?? null) : null;

            if (is_array($rules)) {
                return $rules;
            }
        }

        return [];
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
}
