<?php

namespace App\Console\Commands;

use App\Services\AI\OllamaEmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReembedCasesCommand extends Command
{
    protected $signature = 'cases:reembed
                            {--batch=50 : Chunks per batch}
                            {--sleep=100 : Milliseconds between batches}
                            {--from=0 : Start from this chunk ID in the source cases table}';

    protected $description = 'Re-embed court decisions into court_cases + court_chunks using BGE-M3 (1024 dims)';

    public function handle(OllamaEmbeddingService $embedder): int
    {
        $batchSize = (int) $this->option('batch');
        $sleep     = (int) $this->option('sleep');
        $fromId    = (int) $this->option('from');

        $total = DB::connection('pgvector')
            ->table('cases')
            ->where('id', '>', $fromId)
            ->count();

        $doneCases  = DB::connection('pgvector')->table('court_cases')->count();
        $doneChunks = DB::connection('pgvector')->table('court_chunks')->count();

        $this->info("სულ: {$total} chunks | court_cases: {$doneCases} | court_chunks: {$doneChunks}");
        $this->info("Batch: {$batchSize} | Model: bge-m3");
        $this->newLine();

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $lastId    = $fromId;
        $processed = 0;

        while (true) {
            $rows = DB::connection('pgvector')
                ->table('cases')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit($batchSize)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            // ── 1. Upsert court_cases (metadata, one row per case) ────────────
            $uniqueCases = $rows->unique('case_id');
            foreach ($uniqueCases as $row) {
                DB::connection('pgvector')->statement('
                    INSERT INTO court_cases
                        (id, case_num, dispute_subject, case_date, category,
                         result, claim_type, kind, chamber, court, case_type)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?)
                    ON CONFLICT (id) DO NOTHING
                ', [
                    $row->case_id,
                    $row->case_num,
                    $row->dispute_subject,
                    $row->case_date,
                    $row->category,
                    $row->result,
                    $row->claim_type,
                    $row->kind,
                    $row->chamber,
                    $row->court,
                    $row->case_type,
                ]);
            }

            // ── 2. Embed + insert court_chunks ────────────────────────────────
            foreach ($rows as $row) {
                $text = mb_substr(
                    trim(preg_replace('/\s+/', ' ', $row->content ?? '')),
                    0,
                    8000
                );
                if (mb_strlen($text) < 5) {
                    $text = 'შინაარსი არ არის';
                }

                try {
                    $embedding = $embedder->embed($text);
                } catch (\Throwable $e) {
                    $this->newLine();
                    $this->warn("Skip chunk id={$row->id}: " . $e->getMessage());
                    $bar->advance();
                    continue;
                }

                $vec = '[' . implode(',', $embedding) . ']';

                // Extract chunk_index from old meta JSON
                $meta       = $row->meta ? json_decode($row->meta, true) : [];
                $chunkIndex = $meta['chunk_index'] ?? null;

                DB::connection('pgvector')->statement('
                    INSERT INTO court_chunks (id, case_id, chunk_index, content, embedding)
                    VALUES (?,?,?,?,?::vector)
                    ON CONFLICT (id) DO UPDATE SET embedding = EXCLUDED.embedding
                ', [
                    $row->id,
                    $row->case_id,
                    $chunkIndex,
                    $row->content,
                    $vec,
                ]);

                $bar->advance();
                $processed++;
            }

            $lastId = $rows->last()->id;
            $this->line(" [{$processed}/{$total}] last_id={$lastId}");

            if ($sleep > 0) {
                usleep($sleep * 1000);
            }
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("დასრულდა! გადაემბედინგდა: {$processed} chunks");

        return self::SUCCESS;
    }
}
