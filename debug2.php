<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// Check შრომის კოდექსი articles
$arts = DB::connection('pgvector')
    ->table('law_articles')
    ->join('laws', 'laws.id', '=', 'law_articles.law_id')
    ->where('laws.title', 'like', '%შრომის%')
    ->get(['law_articles.id', 'law_articles.article_num', 'law_articles.article_title', 'law_articles.chunk_index']);

echo "=== შრომის კოდექსი articles (" . $arts->count() . ") ===" . PHP_EOL;
foreach ($arts as $a) {
    echo "  chunk {$a->chunk_index}: {$a->article_num} | {$a->article_title}" . PHP_EOL;
}

// Check last Laravel log entries
echo PHP_EOL . "=== Recent logs ===" . PHP_EOL;
$logFile = storage_path('logs/laravel.log');
if (file_exists($logFile)) {
    $lines = array_slice(file($logFile), -30);
    foreach ($lines as $line) {
        if (str_contains($line, 'Matsne') || str_contains($line, 'FetchMatsne') || str_contains($line, 'LawRetriever')) {
            echo trim($line) . PHP_EOL;
        }
    }
}
