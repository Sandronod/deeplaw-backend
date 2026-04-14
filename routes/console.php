<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Refresh Matsne law map every Sunday at 03:00
Schedule::command('matsne:refresh-map')->weekly()->sundays()->at('03:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/matsne-map-refresh.log'));

// Re-fetch all indexed laws monthly (picks up legislative changes)
Schedule::command('matsne:refresh-laws')->monthly()->at('04:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/matsne-laws-refresh.log'));

// Full Matsne knowledge base sync — nightly at 02:00, 150 laws per run
// Hash-based: skips unchanged laws, 8-15s delay + 90s pause every 50 laws
// 997 laws ÷ 150/night ≈ 7 nights for full initial sync
// After initial sync: only changed laws get re-fetched (fast)
Schedule::command('matsne:sync-all --force --yes --limit=150')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/matsne-sync.log'));

// Sync ECHR seed corpus weekly (Georgia cases + top Article 6/8/10/3, importance 1+2)
Schedule::job(\App\Jobs\SyncTopEchrTopicsJob::class)->weekly()->wednesdays()->at('02:00')
    ->withoutOverlapping();
