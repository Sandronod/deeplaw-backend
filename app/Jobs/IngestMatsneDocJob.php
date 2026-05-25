<?php

namespace App\Jobs;

use App\Services\AI\OllamaEmbeddingService;
use App\Services\Matsne\MatsneFetchService;
use App\Services\Matsne\MatsneHtmlParserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class IngestMatsneDocJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    private const CHUNK_SIZE    = 1500;
    private const CHUNK_OVERLAP = 200;
    private const MAX_CHARS     = 8000; // Ollama BGE-M3 context limit
    private const CHUNKS_TABLE  = 'matsne_chunks_v2';

    public function __construct(public readonly int $matsneId) {}

    public function handle(
        MatsneFetchService      $fetcher,
        MatsneHtmlParserService $parser,
        OllamaEmbeddingService  $embedder,
    ): void {
        $this->markProcessing();

        try {
            // Skip if already ingested (and not re-run forced)
            if ($this->alreadyDone()) {
                $this->markDone();
                return;
            }

            // 1. Fetch HTML
            $html = $fetcher->fetchHtml($this->matsneId);

            if (empty($html)) {
                $this->markFailed('Empty HTML response');
                return;
            }

            if (str_contains($html, 'Access Denied') || str_contains($html, 'Something went wrong')) {
                $this->markFailed('Access Denied by Matsne WAF');
                return;
            }

            // 2. Parse articles + metadata
            $parsed   = $parser->parse($html, $this->matsneId);
            $articles = $parsed['articles'] ?? [];
            $meta     = $parsed['meta'] ?? [];

            if (empty($articles)) {
                $this->markSkipped();
                return;
            }

            // 3. Build full text from articles
            $fullText = collect($articles)
                ->map(fn($a) => trim(($a['article_title'] ?? '') . "\n" . ($a['content'] ?? '')))
                ->filter()
                ->implode("\n\n");

            if (mb_strlen($fullText) < 10) {
                $this->markFailed('Text too short');
                return;
            }

            // 4. Chunk text
            $chunks = $this->chunk($fullText);

            // 5. Get title/doc_type from queue (fallback) or parser
            $queueRow = DB::connection('pgvector')
                ->table('matsne_doc_queue')
                ->where('matsne_id', $this->matsneId)
                ->first();

            $title   = $parsed['title'] ?? $queueRow?->title;
            $docType = $parsed['meta']['doc_type'] ?? $queueRow?->doc_type;

            // Compute effective years for fast temporal filtering
            $fromYear = $meta['effective_from']
                ? (int) substr($meta['effective_from'], 0, 4)
                : ($meta['signing_date'] ? (int) substr($meta['signing_date'], 0, 4) : null);
            $toYear = $meta['effective_to']
                ? (int) substr($meta['effective_to'], 0, 4)
                : null;

            // 6. Upsert matsne_documents
            $hash = md5($fullText);

            $existing = DB::connection('pgvector')
                ->table('matsne_documents')
                ->where('matsne_id', $this->matsneId)
                ->first();

            $docData = [
                'title'          => $title,
                'doc_type'       => mb_substr((string) ($docType ?? ''), 0, 100) ?: null,
                'doc_number'     => mb_substr((string) ($meta['doc_number'] ?? ''), 0, 100) ?: null,
                'issuer'         => mb_substr((string) ($meta['issuer'] ?? ''), 0, 300) ?: null,
                'signing_date'   => $meta['signing_date'] ?? null,
                'publish_date'   => $meta['publish_date'] ?? null,
                'effective_from' => $meta['effective_from'] ?? null,
                'effective_to'   => $meta['effective_to'] ?? null,
                'is_active'      => $meta['is_active'] ?? true,
                'content'        => $fullText,
                'content_hash'   => $hash,
            ];

            if ($existing && $existing->content_hash === $hash) {
                $this->markDone();
                return;
            }

            if ($existing) {
                DB::connection('pgvector')
                    ->table(self::CHUNKS_TABLE)
                    ->where('document_id', $existing->id)
                    ->delete();

                DB::connection('pgvector')
                    ->table('matsne_documents')
                    ->where('id', $existing->id)
                    ->update(array_merge($docData, ['updated_at' => now()]));

                $documentId = $existing->id;
            } else {
                $documentId = DB::connection('pgvector')
                    ->table('matsne_documents')
                    ->insertGetId(array_merge($docData, [
                        'matsne_id'  => $this->matsneId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));
            }

            // 7. Embed chunks and insert
            foreach ($chunks as $index => $chunkText) {
                $embedding = $embedder->embed(mb_substr($chunkText, 0, self::MAX_CHARS));

                DB::connection('pgvector')->statement(
                    'INSERT INTO ' . self::CHUNKS_TABLE . '
                        (document_id, matsne_id, title, doc_type, issuer, is_active,
                         effective_from_year, effective_to_year,
                         chunk_index, content, embedding, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::vector, ?, ?)',
                    [
                        $documentId,
                        $this->matsneId,
                        $title,
                        $docType,
                        mb_substr((string) ($meta['issuer'] ?? ''), 0, 300) ?: null,
                        $meta['is_active'] ?? true,
                        $fromYear,
                        $toYear,
                        $index,
                        $chunkText,
                        '[' . implode(',', $embedding) . ']',
                        now(),
                        now(),
                    ]
                );
            }

            $this->markDone();

            Log::debug('IngestMatsneDocJob done', [
                'matsne_id' => $this->matsneId,
                'chunks'    => count($chunks),
            ]);
        } catch (\Throwable $e) {
            $this->markFailed($e->getMessage());
            Log::warning('IngestMatsneDocJob failed', [
                'matsne_id' => $this->matsneId,
                'error'     => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function chunk(string $text): array
    {
        $chunks  = [];
        $len     = mb_strlen($text);
        $offset  = 0;

        while ($offset < $len) {
            $chunk = mb_substr($text, $offset, self::CHUNK_SIZE);
            if (mb_strlen(trim($chunk)) > 20) {
                $chunks[] = trim($chunk);
            }
            $offset += self::CHUNK_SIZE - self::CHUNK_OVERLAP;
        }

        return $chunks;
    }

    private function alreadyDone(): bool
    {
        return DB::connection('pgvector')
            ->table('matsne_doc_queue')
            ->where('matsne_id', $this->matsneId)
            ->where('status', 'done')
            ->exists();
    }

    private function markProcessing(): void
    {
        DB::connection('pgvector')
            ->table('matsne_doc_queue')
            ->where('matsne_id', $this->matsneId)
            ->whereIn('status', ['queued', 'pending', 'processing'])
            ->update(['status' => 'processing']);
    }

    private function markDone(): void
    {
        DB::connection('pgvector')
            ->table('matsne_doc_queue')
            ->where('matsne_id', $this->matsneId)
            ->update(['status' => 'done', 'processed_at' => now(), 'error' => null]);
    }

    private function markSkipped(): void
    {
        DB::connection('pgvector')
            ->table('matsne_doc_queue')
            ->where('matsne_id', $this->matsneId)
            ->update(['status' => 'skipped', 'error' => null]);
    }

    private function markFailed(string $reason): void
    {
        DB::connection('pgvector')
            ->table('matsne_doc_queue')
            ->where('matsne_id', $this->matsneId)
            ->update(['status' => 'failed', 'error' => mb_substr($reason, 0, 500)]);
    }
}
