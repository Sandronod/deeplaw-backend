<?php

namespace App\Console\Commands;

use App\Jobs\IngestMatsneDocJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Dispatches IngestMatsneDocJob for all pending documents in matsne_doc_queue.
 * Safe to stop (Ctrl+C) and re-run — completed docs are skipped automatically.
 *
 * Usage:
 *   # Dispatch to queue (run workers separately)
 *   php artisan matsne:ingest
 *
 *   # Process synchronously (no queue, single process)
 *   php artisan matsne:ingest --sync
 *
 *   # Retry failed docs too
 *   php artisan matsne:ingest --retry-failed
 *
 *   # Limit how many to dispatch this run
 *   php artisan matsne:ingest --limit=1000
 *
 * Running workers (open multiple terminals for parallel processing):
 *   php artisan queue:work --queue=matsne --sleep=1 --tries=3
 */
class IngestMatsneDocsCommand extends Command
{
    protected $signature = 'matsne:ingest
        {--queue         : Dispatch to queue instead of processing synchronously}
        {--retry-failed  : Also retry documents that previously failed}
        {--limit=0       : Max documents to dispatch (0 = all pending)}
        {--delay=2       : Seconds between dispatches in sync mode}';

    protected $description = 'Ingest Matsne documents: fetch, parse, embed, store (resumable)';

    public function handle(): int
    {
        $sync         = ! $this->option('queue');
        $retryFailed  = $this->option('retry-failed');
        $limit        = (int) $this->option('limit');
        $delay        = (int) $this->option('delay');

        $statuses = ['pending'];
        if ($retryFailed) {
            // Reset failed + stuck queued → pending so they can re-run
            DB::connection('pgvector')
                ->table('matsne_doc_queue')
                ->whereIn('status', ['failed', 'queued'])
                ->update(['status' => 'pending', 'error' => null]);
        }

        $query = DB::connection('pgvector')
            ->table('matsne_doc_queue')
            ->whereIn('status', $statuses)
            ->orderBy('matsne_id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No pending documents. Queue is clear!');
            $this->printStats();
            return 0;
        }

        $mode = $sync ? 'synchronous' : 'queue (matsne)';
        $this->info("Dispatching {$total} documents [{$mode}]...");

        $bar       = $this->output->createProgressBar($total);
        $dispatched = 0;

        $query->chunk(500, function ($rows) use ($sync, $delay, $bar, &$dispatched) {
            foreach ($rows as $row) {
                if ($sync) {
                    try {
                        IngestMatsneDocJob::dispatchSync($row->matsne_id);
                    } catch (\Throwable $e) {
                        // Job already marked itself as failed; continue to next
                    }
                    if ($delay > 0) {
                        sleep($delay);
                    }
                } else {
                    // Atomically transition pending → queued using RETURNING.
                    // If another process already claimed this record, affected = 0 → skip dispatch.
                    $claimed = DB::connection('pgvector')->select(
                        'UPDATE matsne_doc_queue SET status = ? WHERE matsne_id = ? AND status = ? RETURNING matsne_id',
                        ['queued', $row->matsne_id, 'pending']
                    );

                    if (empty($claimed)) {
                        continue; // already queued/processing by another instance
                    }

                    IngestMatsneDocJob::dispatch($row->matsne_id)->onQueue('matsne');
                }

                $bar->advance();
                $dispatched++;
            }
        });

        $bar->finish();
        $this->newLine();

        $this->info("Dispatched {$dispatched} jobs.");

        if (! $sync) {
            $this->line('');
            $this->line('Run workers in separate terminals:');
            $this->line("  php artisan queue:work --queue=matsne --sleep=1 --tries=3");
            $this->line("  php artisan queue:work --queue=matsne --sleep=1 --tries=3");
            $this->line("  php artisan queue:work --queue=matsne --sleep=1 --tries=3");
        }

        $this->newLine();
        $this->printStats();

        return 0;
    }

    private function printStats(): void
    {
        $counts = DB::connection('pgvector')
            ->table('matsne_doc_queue')
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $this->table(
            ['Status', 'Count'],
            $counts->map(fn($cnt, $status) => [$status, number_format($cnt)])->values()
        );
    }
}
