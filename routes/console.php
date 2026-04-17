<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// All scheduled jobs disabled — run manually as needed:
// artisan matsne:refresh-map
// artisan matsne:refresh-laws
// artisan matsne:sync-all --yes
// artisan app:sync-top-echr-topics
