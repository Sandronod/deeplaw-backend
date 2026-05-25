<?php

namespace App\DTOs;

readonly class IssueList
{
    public function __construct(
        /** @var LegalIssue[] */
        public array $issues,
        public int   $issueCount,
        public bool  $isComplex,   // true when > 2 issues
    ) {}

    public static function empty(): self
    {
        return new self(issues: [], issueCount: 0, isComplex: false);
    }

    public function domains(): array
    {
        return array_values(array_unique(
            array_map(fn(LegalIssue $i) => $i->domain, $this->issues)
        ));
    }

    public function keywords(): array
    {
        $all = [];
        foreach ($this->issues as $issue) {
            $all = array_merge($all, $issue->keywords);
        }
        return array_values(array_unique($all));
    }

    public function toArray(): array
    {
        return array_map(fn(LegalIssue $i) => $i->toArray(), $this->issues);
    }

    public function toPromptBlock(): string
    {
        if (empty($this->issues)) {
            return '';
        }

        $lines = ["MANDATORY ISSUES — სავალდებულოა ყველა ({$this->issueCount}/{$this->issueCount}):\n"];

        foreach ($this->issues as $i => $issue) {
            $num    = $i + 1;
            $domain = $issue->domainLabel();
            $lines[] = "  [{$num}] {$domain} | {$issue->title}";
        }

        $lines[] = "\n❗ ყოველ საკითხზე სავალდებულოა სრული IRAC:";
        $lines[] = "   📌 Issue   — კონკრეტული სამართლებრივი კითხვა";
        $lines[] = "   📕 Rule    — კანონი/ნორმა CONTEXT-იდან";
        $lines[] = "   ⚖️ Cases   — სასამ. პრაქტიკა CONTEXT-იდან";
        $lines[] = "   🔍 Apply   — ფაქტებზე გამოყენება";
        $lines[] = "   ✅ Conclude — კონკრეტული პოზიცია";
        $lines[] = "\n❗ გამოტოვება = შეცდომა. {$this->issueCount}/{$this->issueCount} სრულად.";

        return implode("\n", $lines);
    }
}
