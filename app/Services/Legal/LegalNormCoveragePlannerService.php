<?php

namespace App\Services\Legal;

class LegalNormCoveragePlannerService
{
    private const LAW_ALIASES = [
        'civil_code' => 'სკ',
        'civil_procedure_code' => 'სსკ',
        'labor_code' => 'შრომის კოდექს',
        'personal_data_law' => 'პერსონალურ მონაცემთა დაცვის შესახებ',
        'admin_procedure_code' => 'ადმ.საპ',
        'general_admin_code' => 'ზაკ',
        'insolvency_law' => 'რეაბილიტაციისა და კრედიტორთა კოლექტიური დაკმაყოფილების შესახებ',
    ];

    public function __construct(
        private readonly LegalIssueNormMapService $normMap,
    ) {}

    /**
     * @param array<int, string> $domains
     * @param array<int, array<string, mixed>> $facts
     * @return array<int, array<string, mixed>>
     */
    public function plan(string $question, array $domains = [], array $facts = []): array
    {
        return $this->normMap->match($question, $domains, $facts);
    }

    /**
     * @param array<int, string> $domains
     * @param array<int, array<string, mixed>> $facts
     * @return array<int, array{num: int, code: string}>
     */
    public function articleRefs(string $question, array $domains = [], array $facts = []): array
    {
        $refs = [];

        foreach ($this->plan($question, $domains, $facts) as $issue) {
            foreach ($this->sourceGroups($issue) as $sourceGroup) {
                $law = (string) ($sourceGroup['law'] ?? '');
                $code = self::LAW_ALIASES[$law] ?? '';
                if ($code === '') {
                    continue;
                }

                foreach ((array) ($sourceGroup['articles'] ?? []) as $article) {
                    $article = (int) $article;
                    if ($article > 0) {
                        $refs[] = ['num' => $article, 'code' => $code];
                    }
                }
            }
        }

        return array_values(array_unique($refs, SORT_REGULAR));
    }

    /**
     * @param array<string, mixed> $issue
     * @return array<int, array<string, mixed>>
     */
    private function sourceGroups(array $issue): array
    {
        return array_merge(
            (array) ($issue['required_sources'] ?? []),
            (array) ($issue['optional_sources'] ?? []),
        );
    }
}
