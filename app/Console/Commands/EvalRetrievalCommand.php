<?php

namespace App\Console\Commands;

use App\Services\AI\OllamaEmbeddingService;
use App\Services\Legal\LegalCaseRetrieverService;
use Illuminate\Console\Command;

/**
 * Evaluates retrieval quality against the gold test set.
 *
 * Metrics:
 *   P@1  — expected case found at rank 1
 *   P@3  — expected case found in top 3
 *   P@5  — expected case found in top 5
 *   MRR  — Mean Reciprocal Rank (1/rank, 0 if not found in top 10)
 *
 * Usage:
 *   php artisan eval:retrieval                    # full pipeline
 *   php artisan eval:retrieval --no-case-vector   # chunk-only (Sprint 1 baseline)
 *   php artisan eval:retrieval --compare          # runs both and shows diff
 *   php artisan eval:retrieval --details          # per-query breakdown
 */
class EvalRetrievalCommand extends Command
{
    protected $signature = 'eval:retrieval
                            {--gold=            : Path to gold set JSON}
                            {--no-case-vector   : Disable case-level embedding search}
                            {--compare          : Run both modes and show improvement}
                            {--details          : Show per-query breakdown}';

    protected $description = 'Evaluate retrieval P@K and MRR against gold test set';

    public function __construct(
        private readonly OllamaEmbeddingService    $embedder,
        private readonly LegalCaseRetrieverService $retriever,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $goldPath = $this->option('gold') ?: storage_path('app/eval/gold_set.json');

        if (!file_exists($goldPath)) {
            $this->error("Gold set not found: {$goldPath}");
            $this->line("Run: php artisan eval:generate-gold");
            return self::FAILURE;
        }

        $goldSet = json_decode(file_get_contents($goldPath), true);

        if ($this->option('compare')) {
            return $this->runComparison($goldSet);
        }

        $skipCaseVector = (bool) $this->option('no-case-vector');
        $label = $skipCaseVector ? 'chunk-vector only (no case-vector)' : 'full pipeline (with case-vector)';

        $this->info("Evaluating " . count($goldSet) . " queries — {$label}");
        $this->newLine();

        $metrics = $this->evaluate($goldSet, $skipCaseVector, showDetails: $this->option('details'));
        $this->printMetrics($metrics);
        $this->printVerdict($metrics['p3']);

        return self::SUCCESS;
    }

    private function runComparison(array $goldSet): int
    {
        $total = count($goldSet);
        $this->info("A/B comparison on {$total} queries...");
        $this->newLine();

        // Pre-embed all queries once (reused for both runs)
        $this->info('Embedding queries (shared for both runs)...');
        $embeddings = [];
        $bar = $this->output->createProgressBar($total);
        $bar->start();
        foreach ($goldSet as $item) {
            $embeddings[$item['id']] = $this->embedder->embed($item['query']);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        // Run A: chunk-vector only
        $this->line('━━━ Run A: chunk-vector only (Sprint 1 baseline) ━━━');
        $metricsA = $this->evaluateWithEmbeddings($goldSet, $embeddings, skipCaseVector: true);
        $this->printMetrics($metricsA);

        $this->newLine();

        // Run B: full pipeline with case-vector
        $this->line('━━━ Run B: full pipeline (+ case-vector, Sprint 2) ━━━');
        $metricsB = $this->evaluateWithEmbeddings($goldSet, $embeddings, skipCaseVector: false);
        $this->printMetrics($metricsB);

        // Diff table
        $this->newLine();
        $this->line('━━━ Improvement (B - A) ━━━');
        $this->table(
            ['Metric', 'Baseline (chunk only)', 'With case-vector', 'Δ'],
            [
                ['P@1', $this->fmt($metricsA['p1']), $this->fmt($metricsB['p1']), $this->delta($metricsA['p1'], $metricsB['p1'])],
                ['P@3', $this->fmt($metricsA['p3']), $this->fmt($metricsB['p3']), $this->delta($metricsA['p3'], $metricsB['p3'])],
                ['P@5', $this->fmt($metricsA['p5']), $this->fmt($metricsB['p5']), $this->delta($metricsA['p5'], $metricsB['p5'])],
                ['MRR', $this->fmt($metricsA['mrr']), $this->fmt($metricsB['mrr']), $this->delta($metricsA['mrr'], $metricsB['mrr'])],
            ]
        );

        return self::SUCCESS;
    }

    // ── Core evaluation ───────────────────────────────────────────────────────

    private function evaluate(array $goldSet, bool $skipCaseVector, bool $showDetails = false): array
    {
        $embeddings = [];
        $bar = $this->output->createProgressBar(count($goldSet));
        $bar->start();
        foreach ($goldSet as $item) {
            $embeddings[$item['id']] = $this->embedder->embed($item['query']);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        $metrics = $this->evaluateWithEmbeddings($goldSet, $embeddings, $skipCaseVector, $showDetails);

        return $metrics;
    }

    private function evaluateWithEmbeddings(array $goldSet, array $embeddings, bool $skipCaseVector, bool $showDetails = false): array
    {
        $hits1 = 0; $hits3 = 0; $hits5 = 0;
        $rrs   = [];
        $rows  = [];

        foreach ($goldSet as $item) {
            $expectedId = (int) $item['expected_case_id'];
            $embedding  = $embeddings[$item['id']] ?? [];

            if (empty($embedding)) {
                $rrs[] = 0.0;
                continue;
            }

            $result      = $this->retriever->retrieve(
                rawEmbedding:   $embedding,
                searchTerms:    '',
                originalQuery:  $item['query'],
                skipCaseVector: $skipCaseVector,
            );
            $retrievedIds = $result->matchedCaseIds;

            $rank = 0;
            foreach ($retrievedIds as $pos => $caseId) {
                if ((int) $caseId === $expectedId) {
                    $rank = $pos + 1;
                    break;
                }
            }

            if ($rank === 1)              $hits1++;
            if ($rank > 0 && $rank <= 3) $hits3++;
            if ($rank > 0 && $rank <= 5) $hits5++;
            $rrs[] = ($rank > 0 && $rank <= 10) ? 1.0 / $rank : 0.0;

            if ($showDetails) {
                $rows[] = [
                    $item['id'],
                    mb_substr($item['query'], 0, 45) . (mb_strlen($item['query']) > 45 ? '…' : ''),
                    $rank > 0 ? "#{$rank}" : 'miss',
                    $item['category'] ?? '',
                ];
            }
        }

        $n = count($rrs);
        $metrics = [
            'p1'  => $n > 0 ? $hits1 / $n : 0,
            'p3'  => $n > 0 ? $hits3 / $n : 0,
            'p5'  => $n > 0 ? $hits5 / $n : 0,
            'mrr' => $n > 0 ? array_sum($rrs) / $n : 0,
            'n'   => $n,
            'hits1' => $hits1, 'hits3' => $hits3, 'hits5' => $hits5,
        ];

        if ($showDetails && !empty($rows)) {
            $this->newLine();
            $this->table(['#', 'Query', 'Rank', 'Category'], $rows);
        }

        return $metrics;
    }

    // ── Output helpers ────────────────────────────────────────────────────────

    private function printMetrics(array $m): void
    {
        $this->table(
            ['Metric', 'Score', 'Hits / Total'],
            [
                ['P@1', $this->fmt($m['p1']), "{$m['hits1']} / {$m['n']}"],
                ['P@3', $this->fmt($m['p3']), "{$m['hits3']} / {$m['n']}"],
                ['P@5', $this->fmt($m['p5']), "{$m['hits5']} / {$m['n']}"],
                ['MRR', $this->fmt($m['mrr']), '—'],
            ]
        );
    }

    private function printVerdict(float $p3): void
    {
        $this->newLine();
        if ($p3 >= 0.80) {
            $this->info('✅ Retrieval quality: GOOD (P@3 ≥ 80%)');
        } elseif ($p3 >= 0.60) {
            $this->warn('⚠️  Retrieval quality: ACCEPTABLE (P@3 60–80%)');
        } else {
            $this->error('❌ Retrieval quality: POOR (P@3 < 60%)');
        }
    }

    private function fmt(float $v): string
    {
        return number_format($v * 100, 1) . '%';
    }

    private function delta(float $a, float $b): string
    {
        $d = ($b - $a) * 100;
        $sign = $d >= 0 ? '+' : '';
        return $sign . number_format($d, 1) . 'pp';
    }
}
