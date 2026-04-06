<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$response = \Illuminate\Support\Facades\Http::withHeaders([
    'User-Agent' => 'LegalCopilot/1.0 (legal research assistant)',
    'Accept'     => 'text/html,application/xhtml+xml',
])->timeout(30)->get('https://matsne.gov.ge/ka/document/view/1155567/0');

$html = $response->body();
echo "Status: " . $response->status() . PHP_EOL;
echo "Bytes: " . mb_strlen($html) . PHP_EOL . PHP_EOL;

// Check part_N ids
preg_match_all('/id="(part_\d+)"/', $html, $m);
echo "=== id=part_N count: " . count($m[1]) . " ===" . PHP_EOL;
echo implode(', ', array_slice($m[1], 0, 10)) . PHP_EOL . PHP_EOL;

// Check მუხლი occurrences
preg_match_all('/მუხლი\s+\d+/u', $html, $m2);
echo "=== მუხლი references: " . count(array_unique($m2[0])) . " ===" . PHP_EOL;
echo implode(', ', array_slice(array_unique($m2[0]), 0, 20)) . PHP_EOL . PHP_EOL;

// Find paragraph structure around მუხლი
preg_match_all('/<(p|div|span|h[1-6])[^>]*>([^<]*მუხლი\s+\d+[^<]*)<\/\1>/u', $html, $m3);
echo "=== Elements containing მუხლი ===" . PHP_EOL;
foreach (array_slice($m3[0], 0, 10) as $el) {
    echo "  " . trim(strip_tags($el)) . PHP_EOL;
}
echo PHP_EOL;

// Show 500 chars around first "მუხლი 1"
$pos = mb_strpos($html, 'მუხლი 1');
if ($pos !== false) {
    echo "=== Context around first 'მუხლი 1' (500 chars) ===" . PHP_EOL;
    echo mb_substr($html, max(0, $pos - 200), 700) . PHP_EOL;
}
