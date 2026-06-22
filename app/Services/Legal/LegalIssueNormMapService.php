<?php

namespace App\Services\Legal;

class LegalIssueNormMapService
{
    /**
     * @param array<string, array<string, mixed>>|null $issues
     */
    public function __construct(
        private readonly ?array $issues = null,
    ) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function issues(): array
    {
        $issues = $this->issues ?? (array) config('legal_issue_norms.issues', []);

        return array_filter(
            $issues,
            fn (array $issue): bool => (bool) ($issue['enabled'] ?? true),
        );
    }

    /**
     * @param array<int, string> $domains
     * @param array<int, array<string, mixed>> $facts
     * @return array<int, array<string, mixed>>
     */
    public function match(string $question, array $domains = [], array $facts = []): array
    {
        $haystack = $this->normalizeText($question . "\n" . $this->factsText($facts));
        $domainSet = array_fill_keys(array_map('strval', $domains), true);
        $matches = [];

        foreach ($this->issues() as $key => $issue) {
            $matchedTriggers = [];
            $score = 0;
            $issueDomains = array_map('strval', (array) ($issue['domains'] ?? [$issue['domain'] ?? '']));
            $hasDomainMatch = !empty(array_intersect_key(array_fill_keys($issueDomains, true), $domainSet));

            if ((bool) ($issue['require_domain_match'] ?? false) && !empty($domainSet) && !$hasDomainMatch) {
                continue;
            }

            $anyMatches = $this->matchedNeedles($haystack, (array) ($issue['trigger_any_keywords'] ?? []));
            if (!empty($anyMatches)) {
                $matchedTriggers = array_merge($matchedTriggers, $anyMatches);
                $score += min(45, 20 + count($anyMatches) * 5);
            }

            $groupMatches = $this->matchedGroups($haystack, (array) ($issue['trigger_all_groups'] ?? []));
            if (!empty($issue['trigger_all_groups']) && $groupMatches['matched_all']) {
                $matchedTriggers = array_merge($matchedTriggers, $groupMatches['needles']);
                $score += 45;
            }

            if (empty($matchedTriggers)) {
                continue;
            }

            if ($hasDomainMatch) {
                $score += 10;
            }

            $priority = (int) ($issue['priority'] ?? 50);
            $matches[] = [
                'key' => (string) $key,
                'title_ka' => (string) ($issue['title_ka'] ?? $key),
                'domain' => (string) ($issue['domain'] ?? ''),
                'confidence' => min(100, $score + min(20, (int) floor($priority / 10))),
                'matched_triggers' => array_values(array_unique($matchedTriggers)),
                'required_sources' => (array) ($issue['required_sources'] ?? []),
                'optional_sources' => (array) ($issue['optional_sources'] ?? []),
                'must_discuss' => (array) ($issue['must_discuss'] ?? []),
                'forbidden_shortcuts' => (array) ($issue['forbidden_shortcuts'] ?? []),
                'boundary_rule' => (string) ($issue['boundary_rule'] ?? ''),
                'priority' => $priority,
            ];
        }

        usort($matches, fn (array $a, array $b): int => [
            $b['priority'],
            $b['confidence'],
            $a['key'],
        ] <=> [
            $a['priority'],
            $a['confidence'],
            $b['key'],
        ]);

        return $matches;
    }

    /**
     * @param array<int, string> $needles
     * @return array<int, string>
     */
    private function matchedNeedles(string $haystack, array $needles): array
    {
        $matches = [];

        foreach ($needles as $needle) {
            $needle = trim((string) $needle);
            if ($needle !== '' && str_contains($haystack, $this->normalizeText($needle))) {
                $matches[] = $needle;
            }
        }

        return $matches;
    }

    /**
     * @param array<int, array<int, string>> $groups
     * @return array{matched_all: bool, needles: array<int, string>}
     */
    private function matchedGroups(string $haystack, array $groups): array
    {
        if (empty($groups)) {
            return ['matched_all' => false, 'needles' => []];
        }

        $matches = [];
        foreach ($groups as $group) {
            $groupMatches = $this->matchedNeedles($haystack, (array) $group);
            if (empty($groupMatches)) {
                return ['matched_all' => false, 'needles' => []];
            }

            $matches[] = $groupMatches[0];
        }

        return ['matched_all' => true, 'needles' => $matches];
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     */
    private function factsText(array $facts): string
    {
        $parts = [];

        foreach ($facts as $fact) {
            foreach (['key', 'label', 'value', 'text', 'unit'] as $field) {
                $value = $fact[$field] ?? null;
                if (is_scalar($value)) {
                    $parts[] = (string) $value;
                }
            }
        }

        return implode("\n", $parts);
    }

    private function normalizeText(string $text): string
    {
        return mb_strtolower(preg_replace('/[ \t]+/u', ' ', trim($text)) ?? $text);
    }
}
