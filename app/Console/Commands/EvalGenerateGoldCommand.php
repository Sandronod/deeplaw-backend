<?php

namespace App\Console\Commands;

use App\Services\AI\OllamaEmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Generates a gold test set for retrieval evaluation.
 *
 * For each candidate case, embeds its legal_issue and checks whether the case
 * appears in the top-N case_embedding results (rank filter). Cases where the
 * legal_issue is too generic (rank > filter-rank) are excluded — they share
 * the same topic with hundreds of other cases and cannot be uniquely retrieved.
 *
 * Usage:
 *   php artisan eval:generate-gold
 *   php artisan eval:generate-gold --count=50 --filter-rank=30 --pool=300
 *   php artisan eval:generate-gold --fresh
 */
class EvalGenerateGoldCommand extends Command
{
    protected $signature = 'eval:generate-gold
                            {--count=50        : Final gold set size}
                            {--filter-rank=30  : Max case_embedding rank allowed (lower = more distinctive)}
                            {--pool=300        : Candidate pool to sample before filtering}
                            {--fresh           : Overwrite existing gold set}';

    protected $description = 'Generate gold retrieval test set (rank-filtered for uniqueness)';

    public function __construct(
        private readonly OllamaEmbeddingService $embedder,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $outputPath  = storage_path('app/eval/gold_set.json');
        $count       = (int) $this->option('count');
        $filterRank  = (int) $this->option('filter-rank');
        $poolSize    = (int) $this->option('pool');

        if (file_exists($outputPath) && !$this->option('fresh')) {
            $existing = json_decode(file_get_contents($outputPath), true);
            $this->info('Gold set exists: ' . count($existing) . ' cases. Use --fresh to regenerate.');
            return self::SUCCESS;
        }

        $this->info("Sampling {$poolSize} candidate cases (balanced by category)...");

        // ── 1. Sample a diverse pool ──────────────────────────────────────────
        $categories = DB::connection('pgvector')
            ->table('court_cases')
            ->whereNotNull('case_card')
            ->whereNotNull('case_embedding')
            ->whereRaw("length(case_card->>'legal_issue') > 80")
            ->select('category')
            ->distinct()
            ->pluck('category')
            ->filter()
            ->values();

        $perCategory = $categories->count() > 0
            ? (int) ceil($poolSize / $categories->count())
            : $poolSize;

        $pool = collect();
        foreach ($categories as $category) {
            $sample = DB::connection('pgvector')
                ->table('court_cases')
                ->whereNotNull('case_card')
                ->whereNotNull('case_embedding')
                ->where('category', $category)
                ->whereRaw("length(case_card->>'legal_issue') > 80")
                ->inRandomOrder()
                ->limit($perCategory)
                ->get(['id', 'case_num', 'category', 'court', 'case_date', 'case_type', 'case_card']);

            $pool = $pool->concat($sample);
        }

        $pool = $pool->shuffle();
        $this->info("Pool size: {$pool->count()}. Filtering by rank ≤ {$filterRank}...");
        $this->newLine();

        // ── 2. Rank-filter: embed legal_issue, check if case is in top-N ─────
        $goldSet    = [];
        $tested     = 0;
        $passed     = 0;
        $failed     = 0;

        $bar = $this->output->createProgressBar($pool->count());
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | pass:%message%');
        $bar->setMessage('0');
        $bar->start();

        foreach ($pool as $row) {
            if ($passed >= $count) {
                break;
            }

            $card = is_string($row->case_card)
                ? json_decode($row->case_card, true)
                : (array) $row->case_card;

            $legalIssue = trim($card['legal_issue'] ?? '');
            if (empty($legalIssue)) {
                $bar->advance();
                continue;
            }

            // Embed this case's legal_issue
            try {
                $embedding = $this->embedder->embed($legalIssue);
            } catch (\Throwable $e) {
                $this->newLine();
                $this->warn("Embed failed for case {$row->id}: {$e->getMessage()}");
                $bar->advance();
                continue;
            }

            if (empty($embedding)) {
                $bar->advance();
                continue;
            }

            // Check rank: is this case in the top-N by case_embedding similarity?
            $vec  = '[' . implode(',', $embedding) . ']';
            $topN = DB::connection('pgvector')
                ->select(
                    "SELECT id FROM court_cases WHERE case_embedding IS NOT NULL ORDER BY case_embedding <=> ?::vector LIMIT ?",
                    [$vec, $filterRank]
                );

            $topIds = array_column($topN, 'id');
            $tested++;

            if (!in_array($row->id, $topIds, false)) {
                $failed++;
                $bar->advance();
                continue;
            }

            // Passes filter — compute actual rank
            $rank = array_search($row->id, $topIds, false) + 1;

            $goldSet[] = [
                'id'               => $passed + 1,
                'query'            => $legalIssue,
                'expected_case_id' => $row->id,
                'case_num'         => $row->case_num,
                'category'         => $row->category,
                'court'            => $row->court,
                'case_date'        => $row->case_date,
                'case_type'        => $row->case_type ?? 'civil',
                'holding'          => $card['holding'] ?? '',
                'outcome'          => $card['outcome'] ?? '',
                'case_embedding_rank' => $rank,
            ];

            $passed++;
            $bar->setMessage((string) $passed);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($passed < $count) {
            $this->warn("Only {$passed}/{$count} cases passed rank filter. Try --pool=" . ($poolSize * 2) . " or --filter-rank=" . ($filterRank * 2));
        }

        // ── 3. Save ───────────────────────────────────────────────────────────
        $dir = storage_path('app/eval');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($outputPath, json_encode($goldSet, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->info("✅ Gold set saved: {$outputPath}");

        $passRate = $tested > 0 ? round($passed / $tested * 100, 1) : 0;
        $this->table(
            ['Metric', 'Value'],
            [
                ['Tested from pool', $tested],
                ['Passed rank filter', $passed],
                ['Rejected (generic)', $failed],
                ['Pass rate', "{$passRate}%"],
                ['Avg rank', $passed > 0 ? round(array_sum(array_column($goldSet, 'case_embedding_rank')) / $passed, 1) : 'N/A'],
            ]
        );

        $this->newLine();
        $this->table(
            ['Category', 'Count'],
            collect($goldSet)
                ->groupBy('category')
                ->map(fn($g, $cat) => [$cat ?: '(none)', count($g)])
                ->sortByDesc(fn($r) => $r[1])
                ->values()
                ->toArray()
        );

        return self::SUCCESS;
    }
}
