<?php

namespace App\Console\Commands;

use App\Jobs\FetchMatsneLawJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RefreshLawsCommand extends Command
{
    protected $signature   = 'matsne:refresh-laws {--delay=15 : Seconds between dispatches to avoid hammering the queue}';
    protected $description = 'Re-fetch all indexed laws from Matsne to pick up legislative changes';

    public function handle(): int
    {
        $delay = (int) $this->option('delay');

        $laws = DB::connection('pgvector')
            ->table('laws')
            ->where('status', 'active')
            ->whereNotNull('matsne_id')
            ->get(['id', 'matsne_id', 'title']);

        if ($laws->isEmpty()) {
            $this->info('No laws in DB to refresh.');
            return 0;
        }

        $this->info("Dispatching refresh for {$laws->count()} laws...");

        foreach ($laws as $law) {
            FetchMatsneLawJob::dispatch((int) $law->matsne_id, $law->title, forceRefresh: true)
                ->onQueue('matsne-fetch')
                ->delay(now()->addSeconds($delay * ($laws->search(fn($l) => $l->id === $law->id))));

            $this->line("  → {$law->title} (matsne_id={$law->matsne_id})");
        }

        $this->info('All jobs dispatched. Worker will process them in the background.');

        Log::info('matsne:refresh-laws dispatched', ['count' => $laws->count()]);

        return 0;
    }
}
