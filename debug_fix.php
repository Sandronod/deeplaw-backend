<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// Remove wrongly fetched law (matsne_id=26350 — old 2006 law)
$law = DB::connection('pgvector')->table('laws')->where('matsne_id', '26350')->first();
if ($law) {
    DB::connection('pgvector')->table('law_articles')->where('law_id', $law->id)->delete();
    DB::connection('pgvector')->table('law_versions')->where('law_id', $law->id)->delete();
    DB::connection('pgvector')->table('laws')->where('id', $law->id)->delete();
    echo "Deleted law id={$law->id} matsne_id=26350 ({$law->title})" . PHP_EOL;
} else {
    echo "law matsne_id=26350 not found" . PHP_EOL;
}

// Fix matsne_laws_map.json — restore correct IDs
$path = database_path('seeds/matsne_laws_map.json');
$map = json_decode(file_get_contents($path), true) ?? [];

// Correct IDs (curated)
$correct = [
    'საქართველოს სამოქალაქო კოდექსი'                    => 31702,
    'სამოქალაქო კოდექსი'                                  => 31702,
    'საქართველოს შრომის კოდექსი'                          => 1155567,
    'შრომის კოდექსი'                                       => 1155567,
    'საქართველოს ადმინისტრაციული საპროცესო კოდექსი'      => 16492,
    'ადმინისტრაციული საპროცესო კოდექსი'                   => 16492,
    'საქართველოს ზოგადი ადმინისტრაციული კოდექსი'         => 16270,
    'ზოგადი ადმინისტრაციული კოდექსი'                      => 16270,
    'საქართველოს საგადასახადო კოდექსი'                    => 1043717,
    'საგადასახადო კოდექსი'                                 => 1043717,
    'საქართველოს სისხლის სამართლის კოდექსი'               => 16426,
    'სისხლის სამართლის კოდექსი'                           => 16426,
    'საქართველოს სისხლის სამართლის საპროცესო კოდექსი'    => 90034,
    'სისხლის სამართლის საპროცესო კოდექსი'                 => 90034,
];

// Override with correct IDs
foreach ($correct as $name => $id) {
    $map[$name] = $id;
}

file_put_contents($path, json_encode($map, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "Map updated. Total entries: " . count($map) . PHP_EOL;
echo "შრომის კოდექსი => " . $map['შრომის კოდექსი'] . PHP_EOL;
