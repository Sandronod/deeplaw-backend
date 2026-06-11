<?php

namespace App\Services\AI;

/**
 * Deterministic query expansion for Georgian legal terms.
 *
 * The LLM extractor is good at shortening questions, but this layer keeps
 * boundary-sensitive legal aliases and rule/fact metadata stable.
 */
class LegalQueryNormalizerService
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private const PHRASE_RULES = [
        [
            'id' => 'property.vindication',
            'any' => ['უკანონო მფლობელობიდან ნივთის გამოთხოვ', 'ნივთის გამოთხოვ', 'გამოთხოვა უკანონო მფლობელობიდან'],
            'terms' => ['ვინდიკაციური სარჩელი', 'უკანონო მფლობელობიდან ნივთის გამოთხოვა', 'საკუთრების დაცვა'],
            'outcome_categories' => ['substantive_outcome.property_remedy'],
        ],
        [
            'id' => 'property.ownership_recognition',
            'any' => ['მესაკუთრედ ცნობ', 'საკუთრების უფლების აღიარ', 'მესაკუთრედ ცნ'],
            'terms' => ['საკუთრების უფლების აღიარება', 'მესაკუთრედ ცნობა'],
            'outcome_categories' => ['substantive_outcome.property_status'],
        ],
        [
            'id' => 'civil_procedure.counterclaim',
            'any' => ['შეგებებულ', 'შემხვედრ სარჩელ'],
            'terms' => ['შეგებებული სარჩელი', 'შემხვედრი სარჩელი', 'სსკ 188'],
            'rule_triggers' => ['civil_procedure.counterclaim_subject_matter_guard'],
            'outcome_categories' => ['procedural_outcome.counterclaim_admissibility'],
        ],
        [
            'id' => 'civil_procedure.magistrate_jurisdiction',
            'all_groups' => [
                ['მაგისტრატ', 'განსჯად', 'კომპეტენც'],
                ['სარჩელის ფას', 'ფასია', 'ღირებულ', 'ლარ', '₾'],
            ],
            'terms' => ['მაგისტრატი მოსამართლე', 'საგნობრივი განსჯადობა', 'სარჩელის ფასი', 'სსკ 9'],
            'rule_triggers' => ['civil_procedure.magistrate_claim_value'],
            'outcome_categories' => ['procedural_outcome.subject_matter_jurisdiction'],
        ],
        [
            'id' => 'civil_procedure.preparatory_hearing',
            'any' => ['მოსამზადებელ სხდომ', 'მოსამზადებელი სხდომ'],
            'terms' => ['მოსამზადებელი სხდომა', 'საპროცესო ეტაპი'],
            'rule_triggers' => ['civil_procedure.counterclaim_preparatory_stage_guard'],
            'outcome_categories' => ['procedural_outcome.counterclaim_admissibility'],
        ],
        [
            'id' => 'civil_procedure.appeal_deadline',
            'any' => ['გასაჩივრ', 'საჩივრ', 'სააპელაციო', 'საკასაციო', 'კასაცი'],
            'terms' => ['გასაჩივრების ვადა', 'საჩივრის დასაშვებობა', 'საპროცესო ვადა'],
            'rule_triggers' => ['civil_procedure.appeal_deadline_guard'],
            'outcome_categories' => ['procedural_outcome.deadline'],
        ],
        [
            'id' => 'civil_procedure.limitation_period',
            'any' => ['ხანდაზმ'],
            'terms' => ['ხანდაზმულობის ვადა', 'მოთხოვნის ხანდაზმულობა'],
            'rule_triggers' => ['civil_procedure.limitation_period_guard'],
            'outcome_categories' => ['procedural_outcome.deadline'],
        ],
    ];

    public function __construct(
        private readonly LegalFactExtractorService $factExtractor,
        private readonly LegalConsequenceTaxonomyService $taxonomy,
    ) {}

    /**
     * @return array{
     *     query:string,
     *     base_query:string,
     *     added_terms:array<int,string>,
     *     rule_triggers:array<int,string>,
     *     outcome_categories:array<int,string>,
     *     outcomes:array<int,string>,
     *     facts:array<int,array<string,mixed>>,
     *     changed:bool
     * }
     */
    public function normalize(string $question, string $extractedQuery = ''): array
    {
        $baseQuery = trim($extractedQuery) !== '' ? trim($extractedQuery) : trim($question);
        $haystack = mb_strtolower($question . "\n" . $baseQuery);

        $terms = $this->queryLines($baseQuery);
        $addedTerms = [];
        $ruleTriggers = [];
        $outcomeCategories = [];

        foreach (self::PHRASE_RULES as $rule) {
            if (!$this->phraseRuleMatches($haystack, $rule)) {
                continue;
            }

            foreach ($rule['terms'] ?? [] as $term) {
                $this->appendUnique($terms, $term);
                $this->appendUnique($addedTerms, $term);
            }

            foreach ($rule['rule_triggers'] ?? [] as $ruleTrigger) {
                $this->appendUnique($ruleTriggers, $ruleTrigger);
            }

            foreach ($rule['outcome_categories'] ?? [] as $category) {
                $this->appendUnique($outcomeCategories, $category);
            }
        }

        $facts = $this->factExtractor->extract($question);
        foreach ($this->termsFromFacts($facts) as $term) {
            $this->appendUnique($terms, $term);
            $this->appendUnique($addedTerms, $term);
        }

        $appliedRules = $this->taxonomy->applyRuleAtoms($question . "\n" . $baseQuery);
        $outcomes = [];
        foreach ($appliedRules as $appliedRule) {
            $this->appendUnique($ruleTriggers, (string) ($appliedRule['key'] ?? ''));
            $this->appendUnique($outcomeCategories, (string) ($appliedRule['category'] ?? ''));
            $this->appendUnique($outcomes, (string) ($appliedRule['outcome'] ?? ''));
        }

        $query = implode("\n", array_slice($terms, 0, 24));

        return [
            'query' => $query,
            'base_query' => $baseQuery,
            'added_terms' => $addedTerms,
            'rule_triggers' => array_values(array_filter($ruleTriggers)),
            'outcome_categories' => array_values(array_filter($outcomeCategories)),
            'outcomes' => array_values(array_filter($outcomes)),
            'facts' => $facts,
            'changed' => $this->normalizeForCompare($query) !== $this->normalizeForCompare($baseQuery),
        ];
    }

    /**
     * @param array<string, mixed> $rule
     */
    private function phraseRuleMatches(string $haystack, array $rule): bool
    {
        $any = $rule['any'] ?? [];
        if (is_array($any) && !empty($any) && $this->containsAny($haystack, $any)) {
            return true;
        }

        $groups = $rule['all_groups'] ?? [];
        if (is_array($groups) && !empty($groups)) {
            foreach ($groups as $group) {
                if (!is_array($group) || !$this->containsAny($haystack, $group)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function queryLines(string $query): array
    {
        $lines = preg_split('/\R+/u', trim($query)) ?: [];
        $lines = array_map(fn (string $line) => trim(preg_replace('/[ \t]+/u', ' ', $line) ?? $line), $lines);

        return array_values(array_filter(array_unique($lines), fn (string $line) => $line !== ''));
    }

    /**
     * @param array<int, array<string, mixed>> $facts
     * @return array<int, string>
     */
    private function termsFromFacts(array $facts): array
    {
        $terms = [];
        foreach ($facts as $fact) {
            $key = (string) ($fact['key'] ?? '');

            if (($fact['type'] ?? null) === 'money' && str_contains($key, 'claim_value')) {
                $this->appendUnique($terms, 'სარჩელის ფასი');
                $this->appendUnique($terms, $this->formatNumber((int) ($fact['value'] ?? 0)) . ' ლარი');
            }

            if (($fact['type'] ?? null) === 'deadline') {
                $this->appendUnique($terms, (string) ($fact['label'] ?? 'საპროცესო ვადა'));
                $this->appendUnique($terms, $this->deadlineText($fact));
            }

            if (($fact['type'] ?? null) === 'procedural_stage') {
                $this->appendUnique($terms, (string) ($fact['text'] ?? 'საპროცესო ეტაპი'));
            }
        }

        return $terms;
    }

    /**
     * @param array<string, mixed> $fact
     */
    private function deadlineText(array $fact): string
    {
        $unit = match ($fact['unit'] ?? null) {
            'day' => 'დღე',
            'month' => 'თვე',
            'year' => 'წელი',
            'week' => 'კვირა',
            'hour' => 'საათი',
            default => '',
        };

        return trim(((string) ($fact['value'] ?? '')) . ' ' . $unit);
    }

    /**
     * @param array<int, string> $items
     */
    private function appendUnique(array &$items, string $item): void
    {
        $item = trim($item);
        if ($item === '') {
            return;
        }

        $normalized = $this->normalizeForCompare($item);
        foreach ($items as $existing) {
            if ($this->normalizeForCompare($existing) === $normalized) {
                return;
            }
        }

        $items[] = $item;
    }

    private function normalizeForCompare(string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }

    private function formatNumber(int $value): string
    {
        return number_format($value, 0, '.', ' ');
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
