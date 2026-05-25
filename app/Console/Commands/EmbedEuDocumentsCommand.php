<?php

namespace App\Console\Commands;

use App\Services\AI\OllamaEmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class EmbedEuDocumentsCommand extends Command
{
    protected $signature = 'eu:embed
        {--batch=50   : Chunks to embed per run}
        {--type=      : Filter by doc_type (regulation|judgement|directive)}';

    protected $description = 'Create embeddings for eu_documents rows that have no embedding yet';

    public function __construct(private OllamaEmbeddingService $embedder)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $batchSize = (int) $this->option('batch');
        $type      = $this->option('type');

        $query = DB::connection('pgvector')
            ->table('eu_documents')
            ->whereNull('embedding')
            ->when($type, fn($q) => $q->where('doc_type', $type));

        $total = $query->count();

        if ($total === 0) {
            $this->info('No documents without embeddings found.');
            return 0;
        }

        $this->info("Found {$total} chunks without embeddings. Embedding in batches of {$batchSize}...");

        $bar  = $this->output->createProgressBar($total);
        $bar->start();
        $done = 0;

        $query->orderBy('id')->chunk($batchSize, function ($rows) use ($bar, &$done) {
            foreach ($rows as $row) {
                try {
                    $embedding = $this->embedder->embed($row->content);
                    ob_start();
                    DB::connection('pgvector')
                        ->table('eu_documents')
                        ->where('id', $row->id)
                        ->update(['embedding' => '[' . implode(',', $embedding) . ']']);
                    ob_end_clean();
                    $done++;
                } catch (\Throwable $e) {
                    if (ob_get_level()) ob_end_clean();
                    $this->newLine();
                    $this->warn("Failed id={$row->id}: " . $e->getMessage());
                }
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Done. Embedded: {$done} / {$total} chunks.");

        return 0;
    }
}
