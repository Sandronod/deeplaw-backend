<?php

namespace Tests\Unit;

use App\Jobs\IngestMatsneDocJob;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class IngestMatsneDocJobTest extends TestCase
{
    public function test_it_chunks_articles_without_crossing_article_boundaries(): void
    {
        $job = new IngestMatsneDocJob(1155567);
        $method = new ReflectionMethod($job, 'chunkArticles');

        $chunks = $method->invoke($job, [
            [
                'article_num' => 'მუხლი 48',
                'article_title' => 'შეწყვეტის წესი',
                'content' => str_repeat('პირველი მუხლის ტექსტი. ', 100),
            ],
            [
                'article_num' => 'მუხლი 49',
                'article_title' => 'მასობრივი დათხოვნა',
                'content' => 'მეორე მუხლის ტექსტი.',
            ],
        ]);

        $article48Chunks = array_values(array_filter(
            $chunks,
            fn (string $chunk) => str_starts_with($chunk, "მუხლი 48\n")
        ));

        $this->assertNotEmpty($article48Chunks);
        $this->assertStringNotContainsString('მუხლი 49', implode("\n", $article48Chunks));
        $this->assertStringStartsWith("მუხლი 49\n", end($chunks));
    }
}
