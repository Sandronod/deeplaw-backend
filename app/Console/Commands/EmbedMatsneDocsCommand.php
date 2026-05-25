<?php

namespace App\Console\Commands;

use App\Services\AI\OllamaEmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 2 of 2-phase Matsne ingestion.
 * Reads full text from matsne_documents.content, chunks and embeds into matsne_chunks_v2.
 * Run only after matsne:fetch-text completes.
 *
 * Usage:
 *   php artisan matsne:embed-docs
 *   php artisan matsne:embed-docs --limit=1000
 */
class EmbedMatsneDocsCommand extends Command
{
    protected $signature = 'matsne:embed-docs
        {--limit=0 : Max documents to process (0 = all)}
        {--delay=0 : Seconds between documents}';

    protected $description = 'Phase 2: Chunk and embed matsne_documents.content into matsne_chunks_v2';

    private const CHUNK_SIZE    = 1500;
    private const CHUNK_OVERLAP = 300;
    private const MAX_CHARS     = 8000;
    private const BATCH         = 100;

    public function handle(OllamaEmbeddingService $embedder): int
    {
        $limit = (int) $this->option('limit');
        $delay = (int) $this->option('delay');

        // Documents with content but no chunks in v2 yet
        $query = DB::connection('pgvector')
            ->table('matsne_documents as d')
            ->whereNotNull('d.content')
            ->whereRaw('length(d.content) > 10')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('matsne_chunks_v2 as c')
                    ->whereColumn('c.document_id', 'd.id');
            })
            ->orderBy('d.id')
            ->select('d.*');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No documents to embed.');
            return 0;
        }

        $this->info("Embedding {$total} documents into matsne_chunks_v2...");

        $bar    = $this->output->createProgressBar($total);
        $done   = 0;
        $failed = 0;

        $query->chunk(self::BATCH, function ($docs) use ($embedder, $bar, $delay, &$done, &$failed) {
            foreach ($docs as $doc) {
                try {
                    $this->embedOne($doc, $embedder);
                    $done++;
                } catch (\Throwable $e) {
                    $failed++;
                    Log::warning('matsne:embed-docs failed', [
                        'matsne_id' => $doc->matsne_id,
                        'error'     => $e->getMessage(),
                    ]);
                }

                $bar->advance();

                if ($delay > 0) {
                    sleep($delay);
                }
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Done: {$done} | Failed: {$failed}");

        $v2total = DB::connection('pgvector')->table('matsne_chunks_v2')->count();
        $this->info("matsne_chunks_v2 total chunks: " . number_format($v2total));

        return 0;
    }

    private function embedOne(object $doc, OllamaEmbeddingService $embedder): void
    {
        $chunks = $this->chunk($doc->content);

        if (empty($chunks)) {
            return;
        }

        // Delete any partial chunks if re-running
        DB::connection('pgvector')
            ->table('matsne_chunks_v2')
            ->where('document_id', $doc->id)
            ->delete();

        foreach ($chunks as $index => $chunkText) {
            $embedding = $embedder->embed(mb_substr($chunkText, 0, self::MAX_CHARS));

            DB::connection('pgvector')->statement(
                'INSERT INTO matsne_chunks_v2
                    (document_id, matsne_id, title, doc_type, issuer, is_active,
                     effective_from_year, effective_to_year,
                     chunk_index, content, embedding, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::vector, ?, ?)',
                [
                    $doc->id,
                    $doc->matsne_id,
                    $doc->title,
                    $doc->doc_type,
                    $doc->issuer,
                    $doc->is_active ?? true,
                    $doc->effective_from ? (int) substr($doc->effective_from, 0, 4) : null,
                    $doc->effective_to   ? (int) substr($doc->effective_to,   0, 4) : null,
                    $index,
                    $chunkText,
                    '[' . implode(',', $embedding) . ']',
                    now(),
                    now(),
                ]
            );
        }

        // Mark queue as done
        DB::connection('pgvector')
            ->table('matsne_doc_queue')
            ->where('matsne_id', $doc->matsne_id)
            ->update(['status' => 'done', 'processed_at' => now(), 'error' => null]);
    }

    private function chunk(string $text): array
    {
        $chunks = [];
        $len    = mb_strlen($text);
        $offset = 0;

        while ($offset < $len) {
            $end = $offset + self::CHUNK_SIZE;

            if ($end < $len) {
                // Find the nearest sentence boundary (. ! ? or newline) within last 200 chars of chunk
                $window     = mb_substr($text, $offset, self::CHUNK_SIZE);
                $boundary   = $this->findSentenceBoundary($window);
                $chunkText  = mb_substr($window, 0, $boundary);
            } else {
                $chunkText = mb_substr($text, $offset);
            }

            $chunkText = trim($chunkText);
            if (mb_strlen($chunkText) > 20) {
                $chunks[] = $chunkText;
            }

            // Advance by (actual chunk length - overlap), minimum 100 to avoid infinite loop
            $advance = max(100, mb_strlen($chunkText) - self::CHUNK_OVERLAP);
            $offset += $advance;
        }

        return $chunks;
    }

    /**
     * Find the best sentence boundary position within a text window.
     * Searches backwards from the end to find `. `, `.\n`, `!\n`, `?\n`, or `\n\n`.
     * Falls back to full window length if no boundary found.
     */
    private function findSentenceBoundary(string $window): int
    {
        $len        = mb_strlen($window);
        $searchFrom = (int) ($len * 0.6); // only look in last 40% of window

        // Prefer paragraph break
        $pos = mb_strrpos($window, "\n\n");
        if ($pos !== false && $pos >= $searchFrom) {
            return $pos + 2;
        }

        // Sentence-ending punctuation followed by space or newline
        foreach (['. ', '.\n', '! ', '!\n', '? ', '?\n'] as $delim) {
            $pos = mb_strrpos($window, $delim);
            if ($pos !== false && $pos >= $searchFrom) {
                return $pos + mb_strlen($delim);
            }
        }

        // Fallback: newline
        $pos = mb_strrpos($window, "\n");
        if ($pos !== false && $pos >= $searchFrom) {
            return $pos + 1;
        }

        return $len;
    }
}
