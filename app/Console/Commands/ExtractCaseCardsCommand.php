<?php

namespace App\Console\Commands;

use App\Services\AI\CaseCardExtractorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Extracts structured case cards (legal_issue, applied_articles, holding, outcome)
 * from court decision text using GPT-4.1-mini and stores the result as JSONB.
 *
 * Usage:
 *   php artisan cases:extract-cards --pilot        # 100 cases only
 *   php artisan cases:extract-cards                # all pending cases
 *   php artisan cases:extract-cards --from=5000    # resume from case id
 *   php artisan cases:extract-cards --fresh        # re-extract all (overwrite)
 */
class ExtractCaseCardsCommand extends Command
{
    protected $signature = 'cases:extract-cards
                            {--pilot   : Process only 100 cases (quality check)}
                            {--fresh   : Re-extract even if case_card already exists}
                            {--from=0  : Start from this court_cases.id}
                            {--batch=5 : Cases per loop iteration (rate limiting)}';

    protected $description = 'Extract structured case cards from court decisions via GPT-4.1-mini';

    public function __construct(
        private readonly CaseCardExtractorService $extractor,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        ini_set('max_execution_time', 0);

        $pilot   = $this->option('pilot');
        $fresh   = $this->option('fresh');
        $fromId  = (int) $this->option('from');
        $batch   = max(1, (int) $this->option('batch'));
        $limit   = $pilot ? 100 : PHP_INT_MAX;

        $this->info($pilot
            ? '🔬 Pilot mode — processing 100 cases'
            : '🚀 Full mode — processing all pending cases'
        );

        $processed = 0;
        $succeeded = 0;
        $failed    = 0;
        $skipped   = 0;

        // Stream rows in batches to avoid loading 80k rows into memory
        $query = DB::connection('pgvector')
            ->table('court_cases')
            ->where('id', '>=', $fromId)
            ->when(!$fresh, fn($q) => $q->whereNull('case_card'))
            ->orderBy('id')
            ->limit($limit);

        $fullCount    = (clone $query)->count();
        $total        = $pilot ? min(100, $fullCount) : $fullCount;
        $this->info("Cases to process: {$total}" . ($pilot ? " (pilot — full db: {$fullCount})" : ''));

        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | ok:%message%');
        $bar->setMessage('0');
        $bar->start();

        $query->chunk($batch, function ($cases) use (&$processed, &$succeeded, &$failed, &$skipped, $bar, $limit) {
            if ($processed >= $limit) {
                return false;
            }

            foreach ($cases as $case) {
                if ($processed >= $limit) {
                    break;
                }

                // Fetch full text from court_chunks
                $chunks = DB::connection('pgvector')
                    ->table('court_chunks')
                    ->where('case_id', $case->id)
                    ->orderBy('chunk_index')
                    ->pluck('content')
                    ->toArray();

                if (empty($chunks)) {
                    $skipped++;
                    $processed++;
                    $bar->advance();
                    continue;
                }

                $fullText = implode("\n\n", $chunks);
                $card     = $this->extractor->extract($fullText);

                if ($card !== null) {
                    DB::connection('pgvector')
                        ->table('court_cases')
                        ->where('id', $case->id)
                        ->update(['case_card' => json_encode($card, JSON_UNESCAPED_UNICODE)]);
                    $succeeded++;
                } else {
                    $failed++;
                    Log::warning('ExtractCaseCards: extraction failed', ['case_id' => $case->id]);
                }

                $processed++;
                $bar->setMessage((string) $succeeded);
                $bar->advance();

                // Gentle rate limiting — 5 req/s max (Batch API has no limit, but streaming does)
                usleep(200_000);
            }
        });

        $bar->finish();
        $this->newLine();

        $this->table(
            ['Total', 'Succeeded', 'Failed', 'Skipped (no text)'],
            [[$processed, $succeeded, $failed, $skipped]]
        );

        if ($failed > 0) {
            $this->warn("Re-run with --from to retry failures, or check logs.");
        }

        $this->info("✅ Done. Run 'php artisan cases:embed-cards' to generate embeddings.");

        return self::SUCCESS;
    }
}
