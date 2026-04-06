<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// Delete შრომის კოდექსი so it gets re-fetched with fixed parser
$law = DB::connection('pgvector')->table('laws')->where('matsne_id', '1155567')->first();
if ($law) {
    DB::connection('pgvector')->table('law_articles')->where('law_id', $law->id)->delete();
    DB::connection('pgvector')->table('law_versions')->where('law_id', $law->id)->delete();
    DB::connection('pgvector')->table('laws')->where('id', $law->id)->delete();
    echo "Deleted law id={$law->id} ({$law->title})" . PHP_EOL;
} else {
    echo "Not found" . PHP_EOL;
}

// Also flush cache
\Illuminate\Support\Facades\Cache::flush();
echo "Cache flushed." . PHP_EOL;
echo PHP_EOL . "Now ask 'შრომის კოდექსი 37 მუხლი' in chat — worker will re-fetch with fixed parser." . PHP_EOL;
