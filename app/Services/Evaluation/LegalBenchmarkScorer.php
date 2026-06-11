<?php

namespace App\Services\Evaluation;

class LegalBenchmarkScorer
{
    public function scoreScenario(array $scenario, array $actual, int $k = 3): array
    {
        $expected = $scenario['expected'] ?? [];
        $topMatsne = array_slice($actual['matsne'] ?? [], 0, $k);
        $topCases = array_slice($actual['case_ids'] ?? [], 0, $k);
        $topEchr = array_slice($actual['echr'] ?? [], 0, $k);

        $expectedLawIds = $this->expectedLawIds($expected);
        $expectedArticleKeys = $this->expectedArticleKeys($expected);
        $actualLawIds = $this->actualLawIds($topMatsne);
        $actualArticleKeys = $this->actualArticleKeys($topMatsne);

        $expectedCaseIds = array_values(array_unique(array_map('intval', $expected['case_ids'] ?? [])));
        $actualCaseIds = array_values(array_unique(array_map('intval', $topCases)));

        $expectedEchrApplications = $this->normalizeApplicationNumbers($expected['echr_applications'] ?? []);
        $actualEchrApplications = $this->actualEchrApplicationNumbers($topEchr);

        $expectedRuleTriggers = $this->stringList($expected['rule_triggers'] ?? []);
        $actualRuleTriggers = $this->stringList($actual['rule_triggers'] ?? []);

        $expectedOutcomeCategories = $this->stringList($expected['outcome_categories'] ?? []);
        $actualOutcomeCategories = $this->stringList($actual['outcome_categories'] ?? []);

        $expectedOutcomes = $this->stringList($expected['outcomes'] ?? []);
        $actualOutcomes = $this->stringList($actual['outcomes'] ?? []);

        $expectedFacts = $this->expectedFactKeys($expected);
        $actualFacts = $this->actualFactKeys($actual['facts'] ?? []);

        $forbidden = $this->forbiddenHits($expected, $topMatsne);

        $law = $this->metric($expectedLawIds, $actualLawIds);
        $articles = $this->metric($expectedArticleKeys, $actualArticleKeys);
        $cases = $this->metric($expectedCaseIds, $actualCaseIds);
        $echr = $this->metric($expectedEchrApplications, $actualEchrApplications);
        $ruleTriggers = $this->metric($expectedRuleTriggers, $actualRuleTriggers);
        $outcomeCategories = $this->metric($expectedOutcomeCategories, $actualOutcomeCategories);
        $outcomes = $this->metric($expectedOutcomes, $actualOutcomes);
        $facts = $this->metric($expectedFacts, $actualFacts);

        $passed = $this->metricPassed($law)
            && $this->metricPassed($articles)
            && $this->metricPassed($cases)
            && $this->metricPassed($echr)
            && $this->metricPassed($ruleTriggers)
            && $this->metricPassed($outcomeCategories)
            && $this->metricPassed($outcomes)
            && $this->metricPassed($facts)
            && empty($forbidden);

        return [
            'id' => $scenario['id'] ?? null,
            'type' => $scenario['type'] ?? 'mixed',
            'passed' => $passed,
            'law' => $law,
            'articles' => $articles,
            'cases' => $cases,
            'echr' => $echr,
            'rule_triggers' => $ruleTriggers,
            'outcome_categories' => $outcomeCategories,
            'outcomes' => $outcomes,
            'facts' => $facts,
            'forbidden_hits' => $forbidden,
            'missing' => [
                'laws' => array_values(array_diff($expectedLawIds, $actualLawIds)),
                'articles' => array_values(array_diff($expectedArticleKeys, $actualArticleKeys)),
                'cases' => array_values(array_diff($expectedCaseIds, $actualCaseIds)),
                'echr_applications' => array_values(array_diff($expectedEchrApplications, $actualEchrApplications)),
                'rule_triggers' => array_values(array_diff($expectedRuleTriggers, $actualRuleTriggers)),
                'outcome_categories' => array_values(array_diff($expectedOutcomeCategories, $actualOutcomeCategories)),
                'outcomes' => array_values(array_diff($expectedOutcomes, $actualOutcomes)),
                'facts' => array_values(array_diff($expectedFacts, $actualFacts)),
            ],
            'actual' => [
                'laws' => $actualLawIds,
                'articles' => $actualArticleKeys,
                'case_ids' => $actualCaseIds,
                'echr_applications' => $actualEchrApplications,
                'rule_triggers' => $actualRuleTriggers,
                'outcome_categories' => $actualOutcomeCategories,
                'outcomes' => $actualOutcomes,
                'facts' => $actualFacts,
            ],
        ];
    }

    public function summarize(array $scores): array
    {
        $summary = [
            'total' => count($scores),
            'passed' => 0,
            'failed' => 0,
            'forbidden_hit_count' => 0,
            'law' => ['matched' => 0, 'total' => 0, 'rate' => null],
            'articles' => ['matched' => 0, 'total' => 0, 'rate' => null],
            'cases' => ['matched' => 0, 'total' => 0, 'rate' => null],
            'echr' => ['matched' => 0, 'total' => 0, 'rate' => null],
            'rule_triggers' => ['matched' => 0, 'total' => 0, 'rate' => null],
            'outcome_categories' => ['matched' => 0, 'total' => 0, 'rate' => null],
            'outcomes' => ['matched' => 0, 'total' => 0, 'rate' => null],
            'facts' => ['matched' => 0, 'total' => 0, 'rate' => null],
        ];

        foreach ($scores as $score) {
            if (($score['passed'] ?? false) === true) {
                $summary['passed']++;
            } else {
                $summary['failed']++;
            }

            $summary['forbidden_hit_count'] += count($score['forbidden_hits'] ?? []);

            foreach (['law', 'articles', 'cases', 'echr', 'rule_triggers', 'outcome_categories', 'outcomes', 'facts'] as $metric) {
                if (($score[$metric]['total'] ?? 0) > 0) {
                    $summary[$metric]['matched'] += $score[$metric]['matched'];
                    $summary[$metric]['total'] += $score[$metric]['total'];
                }
            }
        }

        foreach (['law', 'articles', 'cases', 'echr', 'rule_triggers', 'outcome_categories', 'outcomes', 'facts'] as $metric) {
            if ($summary[$metric]['total'] > 0) {
                $summary[$metric]['rate'] = $summary[$metric]['matched'] / $summary[$metric]['total'];
            }
        }

        $summary['pass_rate'] = $summary['total'] > 0
            ? $summary['passed'] / $summary['total']
            : null;

        return $summary;
    }

    private function expectedLawIds(array $expected): array
    {
        $ids = [];
        foreach ($expected['matsne'] ?? [] as $entry) {
            if (isset($entry['matsne_id'])) {
                $ids[] = (int) $entry['matsne_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    private function expectedArticleKeys(array $expected): array
    {
        $keys = [];
        foreach ($expected['matsne'] ?? [] as $entry) {
            if (!isset($entry['matsne_id'])) {
                continue;
            }

            foreach ($entry['articles'] ?? [] as $article) {
                $keys[] = ((int) $entry['matsne_id']) . ':' . ((int) $article);
            }
        }

        return array_values(array_unique($keys));
    }

    private function actualLawIds(array $matsneResults): array
    {
        return array_values(array_unique(array_map(
            fn (array $result) => (int) ($result['matsne_id'] ?? 0),
            array_filter($matsneResults, fn (array $result) => isset($result['matsne_id']))
        )));
    }

    private function actualArticleKeys(array $matsneResults): array
    {
        $keys = [];

        foreach ($matsneResults as $result) {
            $matsneId = $result['matsne_id'] ?? null;
            $article = $result['_article_num'] ?? $result['article_num'] ?? null;

            if ($matsneId !== null && $article !== null) {
                $keys[] = ((int) $matsneId) . ':' . ((int) $article);
            }
        }

        return array_values(array_unique($keys));
    }

    private function actualEchrApplicationNumbers(array $echrResults): array
    {
        $numbers = [];

        foreach ($echrResults as $result) {
            $numbers[] = $result['application_no']
                ?? $result['application_number']
                ?? $result['applicationNumber']
                ?? null;
        }

        return $this->normalizeApplicationNumbers(array_filter($numbers));
    }

    private function normalizeApplicationNumbers(array $numbers): array
    {
        return array_values(array_unique(array_map(
            fn ($number) => preg_replace('/\s+/u', '', (string) $number),
            $numbers
        )));
    }

    private function metric(array $expected, array $actual): array
    {
        $expected = array_values(array_unique($expected));
        $actual = array_values(array_unique($actual));
        $matched = count(array_intersect($expected, $actual));
        $total = count($expected);

        return [
            'matched' => $matched,
            'total' => $total,
            'rate' => $total > 0 ? $matched / $total : null,
        ];
    }

    private function metricPassed(array $metric): bool
    {
        return ($metric['total'] ?? 0) === 0
            || (($metric['matched'] ?? 0) === ($metric['total'] ?? 0));
    }

    private function stringList(array $items): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($item) => trim((string) $item),
            $items,
        ), fn (string $item) => $item !== '')));
    }

    private function expectedFactKeys(array $expected): array
    {
        return $this->factKeys($expected['facts'] ?? []);
    }

    private function actualFactKeys(array $facts): array
    {
        return $this->factKeys($facts);
    }

    private function factKeys(array $facts): array
    {
        $keys = [];

        foreach ($facts as $fact) {
            if (!is_array($fact) || !isset($fact['key'])) {
                continue;
            }

            $value = $fact['value'] ?? null;
            $key = (string) $fact['key'];
            if ($value !== null) {
                $key .= '=' . (is_numeric($value) ? (string) (int) $value : (string) $value);
            }

            if (isset($fact['unit']) && $fact['unit'] !== '') {
                $key .= ':' . (string) $fact['unit'];
            }

            $keys[] = $key;
        }

        return array_values(array_unique($keys));
    }

    private function forbiddenHits(array $expected, array $matsneResults): array
    {
        $hits = [];
        $forbiddenIds = array_values(array_unique(array_map('intval', $expected['forbidden_matsne_ids'] ?? [])));
        $actualLawIds = $this->actualLawIds($matsneResults);

        foreach (array_intersect($forbiddenIds, $actualLawIds) as $matsneId) {
            $hits[] = "law:{$matsneId}";
        }

        $actualArticleKeys = $this->actualArticleKeys($matsneResults);
        foreach ($this->forbiddenArticleKeys($expected) as $key) {
            if (in_array($key, $actualArticleKeys, true)) {
                $hits[] = "article:{$key}";
            }
        }

        return array_values(array_unique($hits));
    }

    private function forbiddenArticleKeys(array $expected): array
    {
        $keys = [];
        foreach ($expected['forbidden_matsne_articles'] ?? [] as $entry) {
            if (!isset($entry['matsne_id'])) {
                continue;
            }

            foreach ($entry['articles'] ?? [] as $article) {
                $keys[] = ((int) $entry['matsne_id']) . ':' . ((int) $article);
            }
        }

        return array_values(array_unique($keys));
    }
}
