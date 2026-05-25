<?php

namespace App\Console\Commands;

use App\Jobs\IngestConstCourtDocJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Dispatches IngestConstCourtDocJob for all pending docs in const_court_queue.
 *
 * Usage:
 *   # Synchronous (one process, slow but simple):
 *   php artisan constcourt:ingest
 *
 *   # Queue mode (fast, run workers in parallel):
 *   php artisan constcourt:ingest --queue
 *   php artisan queue:work --queue=constcourt --sleep=1 --tries=3
 *
 *   # Retry failed docs:
 *   php artisan constcourt:ingest --retry-failed
 */
class IngestConstCourtDocsCommand extends Command
{
    protected $signature = 'constcourt:ingest
        {--queue         : Dispatch to queue (run workers separately)}
        {--retry-failed  : Also retry previously failed docs}
        {--limit=0       : Max docs to dispatch (0=all)}
        {--delay=1       : Seconds between sync dispatches}';

    protected $description = 'Ingest Constitutional Court decisions: fetch, parse, embed, store';

    public function handle(): int
    {
        $sync        = !$this->option('queue');
        $retryFailed = $this->option('retry-failed');
        $limit       = (int) $this->option('limit');
        $delay       = (int) $this->option('delay');

        if ($retryFailed) {
            $reset = DB::connection('pgvector')
                ->table('const_court_queue')
                ->whereIn('status', ['failed', 'queued'])
                ->update(['status' => 'pending', 'error' => null]);
            $this->info("Reset {$reset} failed/queued docs to pending.");
        }

        $query = DB::connection('pgvector')
            ->table('const_court_queue')
            ->where('status', 'pending')
            ->orderBy('legal_id');

        if ($limit > 0) $query->limit($limit);

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No pending documents. Queue is clear!');
            $this->printStats();
            return 0;
        }

        $mode = $sync ? 'synchronous' : 'queue (constcourt)';
        $this->info("Dispatching {$total} documents [{$mode}]...");

        $bar        = $this->output->createProgressBar($total);
        $dispatched = 0;

        $query->chunk(500, function ($rows) use ($sync, $delay, $bar, &$dispatched) {
            foreach ($rows as $row) {
                if ($sync) {
                    try {
                        IngestConstCourtDocJob::dispatchSync($row->legal_id);
                    } catch (\Throwable) {
                        // Job marks itself as failed; continue
                    }
                    if ($delay > 0) sleep($delay);
                } else {
                    $claimed = DB::connection('pgvector')->select(
                        'UPDATE const_court_queue SET status = ? WHERE legal_id = ? AND status = ? RETURNING legal_id',
                        ['queued', $row->legal_id, 'pending']
                    );
                    if (empty($claimed)) continue;
                    IngestConstCourtDocJob::dispatch($row->legal_id)->onQueue('constcourt');
                }
                $bar->advance();
                $dispatched++;
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Dispatched {$dispatched} jobs.");

        if (!$sync) {
            $this->newLine();
            $this->line('Start workers in separate terminals:');
            $this->line('  php artisan queue:work --queue=constcourt --sleep=1 --tries=3');
            $this->line('  php artisan queue:work --queue=constcourt --sleep=1 --tries=3');
        }

        $this->newLine();
        $this->printStats();

        return 0;
    }

    private function printStats(): void
    {
        $counts = DB::connection('pgvector')
            ->table('const_court_queue')
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $this->table(
            ['Status', 'Count'],
            $counts->map(fn($cnt, $status) => [$status, number_format($cnt)])->values()
        );
    }
}
