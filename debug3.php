<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Fetch the actual page and show its structure
$response = \Illuminate\Support\Facades\Http::withHeaders([
    'User-Agent' => 'LegalCopilot/1.0 (legal research assistant)',
    'Accept'     => 'text/html,application/xhtml+xml',
])->timeout(30)->get('https://matsne.gov.ge/ka/document/view/26350/0');

$html = $response->body();
echo "Status: " . $response->status() . PHP_EOL;
echo "Bytes: " . mb_strlen($html) . PHP_EOL . PHP_EOL;

// Check what IDs exist in the HTML
preg_match_all('/id="(part_\d+|chapter_\d+|article_\d+|section_\d+)"/', $html, $m);
echo "=== id=part_N / article_N / etc ===" . PHP_EOL;
echo implode(', ', array_unique($m[1])) . PHP_EOL . PHP_EOL;

// Check for მუხლი occurrences
preg_match_all('/მუხლი\s+\d+/u', $html, $m2);
$uniq = array_unique($m2[0]);
echo "=== მუხლი references (" . count($uniq) . ") ===" . PHP_EOL;
echo implode(', ', array_slice($uniq, 0, 20)) . PHP_EOL . PHP_EOL;

// Show first 3000 chars of body content area
preg_match('/<div[^>]+class="[^"]*field-item[^"]*"[^>]*>(.*?)<\/div>/s', $html, $m3);
if ($m3) {
    echo "=== field-item content (first 1000 chars) ===" . PHP_EOL;
    echo mb_substr(strip_tags($m3[1]), 0, 1000) . PHP_EOL;
}

// Check for documentParts JS object
if (preg_match('/documentParts\s*=\s*(\{[^;]+\})/s', $html, $m4)) {
    echo PHP_EOL . "=== documentParts (first 500 chars) ===" . PHP_EOL;
    echo mb_substr($m4[1], 0, 500) . PHP_EOL;
}

// Check article structure
preg_match_all('/<h\d[^>]*>(.*?მუხლი.*?)<\/h\d>/us', $html, $m5);
echo PHP_EOL . "=== Headings with მუხლი ===" . PHP_EOL;
foreach (array_slice($m5[1], 0, 10) as $h) {
    echo "  " . trim(strip_tags($h)) . PHP_EOL;
}
