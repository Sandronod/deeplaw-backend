<?php

namespace App\Console\Commands;

use App\DTOs\EchrResult;
use App\Models\Chat;
use App\Services\Evaluation\LegalBenchmarkScorer;
use App\Services\Legal\LegalChatOrchestratorService;
use Illuminate\Console\Command;

class BenchmarkLegalCommand extends Command
{
    protected $signature = 'benchmark:legal
                            {--file= : Benchmark JSON file path}
                            {--limit= : Limit scenarios}
                            {--k=3 : Hit cutoff for laws, articles, cases, and ECHR}
                            {--sources= : Override sources, comma-separated}
                            {--details : Show per-scenario details}
                            {--json= : Write a JSON report}
                            {--keep-chats : Keep temporary benchmark chats}';

    protected $description = 'Run legal retrieval benchmark scenarios for norms, articles, cases, and ECHR';

    public function __construct(
        private readonly LegalChatOrchestratorService $orchestrator,
        private readonly LegalBenchmarkScorer $scorer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = $this->resolvePath($this->option('file') ?: 'tests/Fixtures/legal_core_benchmark.json');
        $payload = $this->loadPayload($path);
        $scenarios = $payload['scenarios'] ?? [];

        if (empty($scenarios)) {
            $this->error("No scenarios found in {$path}");
            return self::FAILURE;
        }

        if ($this->option('limit') !== null) {
            $scenarios = array_slice($scenarios, 0, (int) $this->option('limit'));
        }

        $k = max(1, (int) $this->option('k'));
        $this->info('Legal benchmark: ' . ($payload['name'] ?? basename($path)));
        $this->line("Scenarios: " . count($scenarios) . " | k={$k}");
        $this->newLine();

        $scores = [];
        $rows = [];
        $bar = $this->output->createProgressBar(count($scenarios));
        $bar->start();

        foreach ($scenarios as $scenario) {
            $score = $this->runScenario($scenario, $k);
            $scores[] = $score;

            $rows[] = [
                $scenario['id'] ?? '',
                ($score['passed'] ?? false) ? 'PASS' : 'FAIL',
                $this->fmtMetric($score['law'] ?? []),
                $this->fmtMetric($score['articles'] ?? []),
                $this->fmtMetric($score['cases'] ?? []),
                $this->fmtMetric($score['echr'] ?? []),
                $this->fmtMetric($score['rule_triggers'] ?? []),
                $this->fmtMetric($score['facts'] ?? []),
                implode(', ', $score['forbidden_hits'] ?? []),
            ];

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $summary = $this->scorer->summarize($scores);
        $this->printSummary($summary);

        if ($this->option('details')) {
            $this->newLine();
            $this->table(
                ['ID', 'Status', 'Law@k', 'Article@k', 'Case@k', 'ECHR@k', 'Rules', 'Facts', 'Forbidden'],
                $rows
            );
        }

        if ($this->option('json')) {
            $reportPath = $this->resolvePath($this->option('json'));
            $this->writeJsonReport($reportPath, [
                'benchmark' => $payload['name'] ?? basename($path),
                'file' => $path,
                'k' => $k,
                'summary' => $summary,
                'scores' => $scores,
            ]);
            $this->info("JSON report written: {$reportPath}");
        }

        return self::SUCCESS;
    }

    private function runScenario(array $scenario, int $k): array
    {
        $chat = null;

        try {
            $chat = Chat::create(['title' => '[benchmark] ' . ($scenario['id'] ?? 'scenario')]);
            $ctx = $this->orchestrator->prepare(
                $chat,
                $scenario['query'],
                $this->sourcesFor($scenario)
            );

            return $this->scorer->scoreScenario($scenario, $this->actualFromContext($ctx), $k);
        } catch (\Throwable $e) {
            return [
                'id' => $scenario['id'] ?? null,
                'type' => $scenario['type'] ?? 'mixed',
                'passed' => false,
                'law' => ['matched' => 0, 'total' => 0, 'rate' => null],
                'articles' => ['matched' => 0, 'total' => 0, 'rate' => null],
                'cases' => ['matched' => 0, 'total' => 0, 'rate' => null],
                'echr' => ['matched' => 0, 'total' => 0, 'rate' => null],
                'rule_triggers' => ['matched' => 0, 'total' => 0, 'rate' => null],
                'outcome_categories' => ['matched' => 0, 'total' => 0, 'rate' => null],
                'outcomes' => ['matched' => 0, 'total' => 0, 'rate' => null],
                'facts' => ['matched' => 0, 'total' => 0, 'rate' => null],
                'forbidden_hits' => [],
                'missing' => [],
                'actual' => [],
                'error' => $e->getMessage(),
            ];
        } finally {
            if ($chat !== null && !$this->option('keep-chats')) {
                $chat->messages()->delete();
                $chat->delete();
            }
        }
    }

    private function actualFromContext(array $ctx): array
    {
        $normalization = $ctx['triageResult']->queryNormalization ?? [];
        $finalDecisionIds = array_values(array_filter(array_map(
            fn (array $decision) => isset($decision['case_id']) ? (int) $decision['case_id'] : null,
            $ctx['finalDecisions'] ?? []
        )));

        return [
            'matsne' => $ctx['matsneResults'] ?? [],
            'case_ids' => !empty($finalDecisionIds)
                ? $finalDecisionIds
                : ($ctx['retrieval']->matchedCaseIds ?? []),
            'echr' => array_map(
                fn ($result) => $this->normalizeEchrResult($result),
                $ctx['echrResults'] ?? []
            ),
            'rule_triggers' => $normalization['rule_triggers'] ?? [],
            'outcome_categories' => $normalization['outcome_categories'] ?? [],
            'outcomes' => $normalization['outcomes'] ?? [],
            'facts' => $normalization['facts'] ?? [],
        ];
    }

    private function normalizeEchrResult(mixed $result): array
    {
        if ($result instanceof EchrResult) {
            return [
                'case_id' => $result->caseId,
                'application_no' => $result->applicationNumber,
                'title' => $result->title,
                'articles' => $result->echrArticles,
            ];
        }

        return is_array($result) ? $result : [];
    }

    private function sourcesFor(array $scenario): array
    {
        if ($this->option('sources')) {
            return array_values(array_filter(array_map('trim', explode(',', $this->option('sources')))));
        }

        return $scenario['sources'] ?? ['court', 'matsne', 'echr'];
    }

    private function loadPayload(string $path): array
    {
        if (!file_exists($path)) {
            throw new \RuntimeException("Benchmark file not found: {$path}");
        }

        $payload = json_decode(file_get_contents($path), true);
        if (!is_array($payload)) {
            throw new \RuntimeException("Invalid benchmark JSON: {$path}");
        }

        return $payload;
    }

    private function resolvePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) || str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }

    private function writeJsonReport(string $path, array $report): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function printSummary(array $summary): void
    {
        $this->table(
            ['Metric', 'Score', 'Hits / Total'],
            [
                ['Scenario pass rate', $this->fmtRate($summary['pass_rate']), "{$summary['passed']} / {$summary['total']}"],
                ['Law hit@k', $this->fmtRate($summary['law']['rate']), "{$summary['law']['matched']} / {$summary['law']['total']}"],
                ['Article hit@k', $this->fmtRate($summary['articles']['rate']), "{$summary['articles']['matched']} / {$summary['articles']['total']}"],
                ['Case hit@k', $this->fmtRate($summary['cases']['rate']), "{$summary['cases']['matched']} / {$summary['cases']['total']}"],
                ['ECHR hit@k', $this->fmtRate($summary['echr']['rate']), "{$summary['echr']['matched']} / {$summary['echr']['total']}"],
                ['Rule trigger hit', $this->fmtRate($summary['rule_triggers']['rate']), "{$summary['rule_triggers']['matched']} / {$summary['rule_triggers']['total']}"],
                ['Outcome category hit', $this->fmtRate($summary['outcome_categories']['rate']), "{$summary['outcome_categories']['matched']} / {$summary['outcome_categories']['total']}"],
                ['Fact extraction hit', $this->fmtRate($summary['facts']['rate']), "{$summary['facts']['matched']} / {$summary['facts']['total']}"],
                ['Forbidden hits', (string) $summary['forbidden_hit_count'], '-'],
            ]
        );
    }

    private function fmtMetric(array $metric): string
    {
        if (($metric['total'] ?? 0) === 0) {
            return '-';
        }

        return ($metric['matched'] ?? 0) . '/' . ($metric['total'] ?? 0);
    }

    private function fmtRate(?float $rate): string
    {
        return $rate === null ? '-' : number_format($rate * 100, 1) . '%';
    }
}
