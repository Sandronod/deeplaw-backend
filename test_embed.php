<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$service = new \App\Services\AI\OllamaEmbeddingService();

$text = \Illuminate\Support\Facades\DB::connection('pgvector')
    ->table('cases')->where('id', 2221)->value('content');

$len = mb_strlen($text);
echo "Total length: {$len}\n";

// Binary search for failing position
$lo = 0;
$hi = $len;

while ($lo < $hi - 10) {
    $mid = (int)(($lo + $hi) / 2);
    $chunk = mb_substr($text, 0, $mid);
    try {
        $service->embed($chunk);
        echo "OK: 0-{$mid}\n";
        $lo = $mid;
    } catch (\Throwable $e) {
        echo "FAIL: 0-{$mid}\n";
        $hi = $mid;
    }
}

echo "\nProblematic range: {$lo} - {$hi}\n";
echo "Text at that range:\n";
echo mb_substr($text, $lo, $hi - $lo) . "\n";
echo "\nHex:\n";
$chunk = mb_substr($text, $lo, $hi - $lo);
for ($i = 0; $i < strlen($chunk); $i++) {
    echo sprintf('%02X ', ord($chunk[$i]));
}
echo "\n";
