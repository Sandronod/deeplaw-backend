<?php

namespace App\Console\Commands;

use App\Services\AI\OllamaEmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EmbedGermanOriginalCommand extends Command
{
    protected $signature = 'german:embed-original
        {--limit=100       : Number of cases to process (0 = all)}
        {--batch=10        : Cases per chunk iteration}
        {--chunk=800       : Chunk size in chars}
        {--overlap=100     : Chunk overlap in chars}';

    protected $description = 'Chunk and embed original German text into german_chunks_de for cross-lingual retrieval testing';

    public function __construct(private readonly OllamaEmbeddingService $embedder)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit     = (int) $this->option('limit');
        $batchSize = (int) $this->option('batch');
        $chunkSize = (int) $this->option('chunk');
        $overlap   = (int) $this->option('overlap');

        // Only process cases not yet in german_chunks_de
        $processed = DB::connection('pgvector')
            ->table('german_chunks_de')
            ->distinct()
            ->pluck('case_id');

        $query = DB::connection('pgvector')
            ->table('german_cases')
            ->whereNotIn('id', $processed)
            ->whereNotNull('content_ka')
            ->whereNotNull('content')
            ->where('content', '!=', '')
            ->select('id', 'external_id', 'content', 'court_name',
                     'level_of_appeal', 'decision_type', 'jurisdiction', 'date');

        $total = min($query->count(), $limit > 0 ? $limit : PHP_INT_MAX);

        if ($total === 0) {
            $this->info('No cases to process.');
            return 0;
        }

        $this->info("Processing {$total} cases (chunk={$chunkSize}, overlap={$overlap})");

        $bar       = $this->output->createProgressBar($total);
        $bar->start();
        $done      = 0;
        $processed = 0;

        $query->orderBy('id')->chunk($batchSize, function ($cases) use (
            &$done, &$processed, $limit, $chunkSize, $overlap, $bar
        ) {
            foreach ($cases as $case) {
                if ($limit > 0 && $processed >= $limit) {
                    return false;
                }

                $chunks = $this->chunkText($case->content, $chunkSize, $overlap);

                foreach ($chunks as $i => $chunkText) {
                    $embedding = null;
                    try {
                        $vec       = $this->embedder->embed($chunkText);
                        $embedding = '[' . implode(',', $vec) . ']';
                    } catch (\Throwable $e) {
                        Log::warning('german:embed-original embed failed', [
                            'case_id' => $case->id,
                            'msg'     => $e->getMessage(),
                        ]);
                    }

                    DB::connection('pgvector')->table('german_chunks_de')->insert([
                        'case_id'         => $case->id,
                        'external_id'     => $case->external_id,
                        'court_name'      => $case->court_name,
                        'level_of_appeal' => $case->level_of_appeal,
                        'decision_type'   => $case->decision_type,
                        'jurisdiction'    => $case->jurisdiction,
                        'date_year'       => $case->date ? (int) substr($case->date, 0, 4) : null,
                        'chunk_index'     => $i,
                        'content'         => $chunkText,
                        'embedding'       => $embedding,
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ]);
                }

                $done++;
                $processed++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Done. Cases processed: {$done}");

        return 0;
    }

    private function chunkText(string $text, int $size, int $overlap): array
    {
        $chunks = [];
        $len    = mb_strlen($text);
        $start  = 0;

        while ($start < $len) {
            $chunk = mb_substr($text, $start, $size);
            if (mb_strlen(trim($chunk)) > 50) {
                $chunks[] = trim($chunk);
            }
            $start += $size - $overlap;
        }

        return $chunks ?: [mb_substr($text, 0, $size)];
    }
}
