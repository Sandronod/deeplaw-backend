<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== LAWS IN DB ===" . PHP_EOL;
$laws = DB::connection('pgvector')->table('laws')->get(['id', 'title', 'status']);
foreach ($laws as $law) {
    echo $law->id . ': ' . $law->title . ' [' . $law->status . ']' . PHP_EOL;
}

echo PHP_EOL . "=== ARTICLES ===" . PHP_EOL;
$total = DB::connection('pgvector')->table('law_articles')->count();
$withEmb = DB::connection('pgvector')->table('law_articles')->whereNotNull('embedding')->count();
echo "Total: $total, With embeddings: $withEmb" . PHP_EOL;

echo PHP_EOL . "=== KEYWORD SEARCH (საგადასახადო) ===" . PHP_EOL;
$rows = DB::connection('pgvector')->select("
    SELECT la.id, la.article_num, la.article_title, l.title as law_title
    FROM law_articles la
    JOIN laws l ON l.id = la.law_id
    WHERE l.status = 'active'
    AND (la.content ILIKE '%საგადასახადო%' OR la.article_title ILIKE '%საგადასახადო%' OR l.title ILIKE '%საგადასახადო%')
    LIMIT 5
");
echo count($rows) . ' results' . PHP_EOL;
foreach ($rows as $r) {
    echo '  - ' . $r->article_num . ' | ' . $r->article_title . ' | ' . $r->law_title . PHP_EOL;
}

echo PHP_EOL . "=== EXACT TITLE MATCH (საგადასახადო კოდექსი) ===" . PHP_EOL;
$query = 'საგადასახადო კოდექსი';
$rows2 = DB::connection('pgvector')->select("
    SELECT la.id, la.law_id, l.title as law_title
    FROM law_articles la
    JOIN laws l ON l.id = la.law_id
    WHERE l.status = 'active'
    AND lower(:q) LIKE '%' || lower(l.title) || '%'
    LIMIT 5
", ['q' => $query]);
echo count($rows2) . ' results' . PHP_EOL;
foreach ($rows2 as $r) {
    echo '  - law_id=' . $r->law_id . ' | ' . $r->law_title . PHP_EOL;
}
