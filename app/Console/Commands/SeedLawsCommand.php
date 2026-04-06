<?php

namespace App\Console\Commands;

use App\Models\Law;
use App\Models\LawArticle;
use App\Services\AI\EmbedCacheService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedLawsCommand extends Command
{
    protected $signature   = 'seed:laws {file : Path to laws JSON file} {--embed : Generate embeddings after seeding}';
    protected $description = 'Seed laws and law articles from a JSON file, optionally embed them.';

    public function __construct(private readonly EmbedCacheService $embedCache)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = $this->argument('file');

        if (!file_exists($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $data = json_decode(file_get_contents($path), true);

        if (!is_array($data)) {
            $this->error('Invalid JSON format. Expected array of laws.');
            return self::FAILURE;
        }

        $this->info("Found " . count($data) . " laws to seed.");

        $seeded   = 0;
        $articles = 0;

        DB::connection('pgvector')->transaction(function () use ($data, &$seeded, &$articles) {
            foreach ($data as $lawData) {
                $law = Law::updateOrCreate(
                    ['matsne_id' => $lawData['matsne_id'] ?? null,
                     'title'     => $lawData['title']],
                    [
                        'matsne_id'   => $lawData['matsne_id'] ?? null,
                        'title'       => $lawData['title'],
                        'document_num'=> $lawData['document_num'] ?? null,
                        'category'    => $lawData['category'] ?? 'კანონი',
                        'status'      => $lawData['status'] ?? 'active',
                        'adopted_at'  => $lawData['adopted_at'] ?? null,
                        'source_url'  => $lawData['source_url'] ?? null,
                    ]
                );

                // Clear existing articles before re-seeding
                $law->articles()->delete();

                foreach ($lawData['articles'] ?? [] as $chunkIdx => $article) {
                    LawArticle::create([
                        'law_id'       => $law->id,
                        'article_num'  => $article['article_num']  ?? null,
                        'article_title'=> $article['article_title'] ?? null,
                        'content'      => $article['content'],
                        'chunk_index'  => $chunkIdx,
                    ]);
                    $articles++;
                }

                $seeded++;
                $this->line("  ✓ {$law->title} ({$law->articles()->count()} articles)");
            }
        });

        $this->info("Seeded {$seeded} laws, {$articles} articles.");

        if ($this->option('embed')) {
            $this->embedArticles();
        } else {
            $this->line('Run with --embed to generate embeddings, or run: php artisan embed:laws');
        }

        return self::SUCCESS;
    }

    private function embedArticles(): void
    {
        $pending = LawArticle::on('pgvector')
            ->whereNull('embedding')
            ->orderBy('id')
            ->get();

        $this->info("Embedding {$pending->count()} articles...");
        $bar = $this->output->createProgressBar($pending->count());
        $bar->start();

        foreach ($pending as $article) {
            $text      = trim(($article->article_num ? $article->article_num . '. ' : '') . $article->content);
            $embedding = $this->embedCache->embed($text);
            $vector    = '[' . implode(',', $embedding) . ']';

            DB::connection('pgvector')->statement(
                'UPDATE law_articles SET embedding = :emb::vector WHERE id = :id',
                ['emb' => $vector, 'id' => $article->id]
            );

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Embedding complete.');
    }
}
