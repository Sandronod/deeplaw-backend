<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// Show all laws with matsne_id
$laws = DB::connection('pgvector')->table('laws')->get(['id', 'matsne_id', 'title', 'status']);
foreach ($laws as $l) {
    $artCount = DB::connection('pgvector')->table('law_articles')->where('law_id', $l->id)->count();
    echo "id={$l->id} matsne_id={$l->matsne_id} articles={$artCount} title={$l->title}" . PHP_EOL;
}

// Clear cache
\Illuminate\Support\Facades\Cache::flush();
echo PHP_EOL . "Cache flushed." . PHP_EOL;
