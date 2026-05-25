<?php

namespace App\Jobs;

use App\Services\AI\OllamaEmbeddingService;
use App\Services\ConstCourt\ConstCourtHtmlParserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IngestConstCourtDocJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 120;

    private const BASE_URL      = 'https://constcourt.ge/ka/judicial-acts';
    private const CHUNK_SIZE    = 1000;
    private const CHUNK_OVERLAP = 150;

    public function __construct(public readonly int $legalId) {}

    public function handle(
        ConstCourtHtmlParserService $parser,
        OllamaEmbeddingService      $embedder,
    ): void {
        $this->markProcessing();

        try {
            if ($this->alreadyDone()) {
                $this->markDone();
                return;
            }

            // 1. Fetch HTML
            $response = Http::withHeaders([
                'User-Agent' => 'LegalCopilot/1.0 (legal research assistant)',
                'Accept'     => 'text/html,application/xhtml+xml',
            ])->timeout(30)->retry(2, 5000)->get(self::BASE_URL, ['legal' => $this->legalId]);

            if (in_array($response->status(), [403, 404])) {
                $this->markSkipped();
                return;
            }

            if ($response->failed()) {
                $this->markFailed("HTTP {$response->status()}");
                return;
            }

            $html = $response->body();
            if (empty($html) || mb_strlen($html) < 500) {
                $this->markSkipped();
                return;
            }

            // 2. Parse
            $parsed  = $parser->parse($html, $this->legalId);
            $content = $parsed['content'] ?? '';

            if (mb_strlen($content) < 50) {
                $this->markSkipped();
                return;
            }

            // 3. Upsert case
            $hash     = md5($content);
            $existing = DB::connection('pgvector')
                ->table('const_court_cases')
                ->where('legal_id', $this->legalId)
                ->first();

            if ($existing && $existing->content_hash === $hash) {
                $this->markDone();
                return;
            }

            $caseData = [
                'case_number'      => mb_substr((string) ($parsed['case_number'] ?? ''), 0, 100) ?: null,
                'case_name'        => $parsed['case_name'] ?? null,
                'decision_type'    => mb_substr((string) ($parsed['decision_type'] ?? ''), 0, 100) ?: null,
                'decision_date'    => $parsed['decision_date'] ?? null,
                'publication_date' => $parsed['publication_date'] ?? null,
                'college'          => mb_substr((string) ($parsed['college'] ?? ''), 0, 200) ?: null,
                'judges'           => $parsed['judges'] ?? null,
                'respondent'       => $parsed['respondent'] ?? null,
                'result'           => $parsed['result'] ?? null,
                'content'          => $content,
                'content_hash'     => $hash,
            ];

            if ($existing) {
                DB::connection('pgvector')->table('const_court_chunks')
                    ->where('case_id', $existing->id)->delete();
                DB::connection('pgvector')->table('const_court_cases')
                    ->where('id', $existing->id)
                    ->update(array_merge($caseData, ['updated_at' => now()]));
                $caseId = $existing->id;
            } else {
                $caseId = DB::connection('pgvector')->table('const_court_cases')
                    ->insertGetId(array_merge($caseData, [
                        'legal_id'   => $this->legalId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));
            }

            // 4. Chunk with overlap + embed
            $chunks = $this->chunk($content);

            foreach ($chunks as $index => $chunkText) {
                $embedding = $embedder->embed($chunkText);

                DB::connection('pgvector')->statement(
                    'INSERT INTO const_court_chunks
                        (case_id, legal_id, case_number, decision_type, decision_date,
                         chunk_index, content, embedding, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?::vector, ?, ?)',
                    [
                        $caseId,
                        $this->legalId,
                        $parsed['case_number'] ?? null,
                        $parsed['decision_type'] ?? null,
                        $parsed['decision_date'] ?? null,
                        $index,
                        $chunkText,
                        '[' . implode(',', $embedding) . ']',
                        now(),
                        now(),
                    ]
                );
            }

            $this->markDone();

            Log::debug('IngestConstCourtDocJob: done', [
                'legal_id' => $this->legalId,
                'chunks'   => count($chunks),
            ]);

        } catch (\Throwable $e) {
            $this->markFailed($e->getMessage());
            Log::warning('IngestConstCourtDocJob: failed', [
                'legal_id' => $this->legalId,
                'error'    => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    // ── Chunking ──────────────────────────────────────────────────────────────

    private function chunk(string $text): array
    {
        $chunks = [];
        $len    = mb_strlen($text);
        $step   = self::CHUNK_SIZE - self::CHUNK_OVERLAP;
        $offset = 0;

        while ($offset < $len) {
            $chunk = trim(mb_substr($text, $offset, self::CHUNK_SIZE));
            if (mb_strlen($chunk) >= 50) {
                $chunks[] = $chunk;
            }
            $offset += $step;
        }

        return $chunks;
    }

    // ── Status helpers ────────────────────────────────────────────────────────

    private function alreadyDone(): bool
    {
        return DB::connection('pgvector')->table('const_court_queue')
            ->where('legal_id', $this->legalId)->where('status', 'done')->exists();
    }

    private function markProcessing(): void
    {
        DB::connection('pgvector')->table('const_court_queue')
            ->where('legal_id', $this->legalId)
            ->whereIn('status', ['pending', 'queued', 'processing'])
            ->update(['status' => 'processing']);
    }

    private function markDone(): void
    {
        DB::connection('pgvector')->table('const_court_queue')
            ->where('legal_id', $this->legalId)
            ->update(['status' => 'done', 'processed_at' => now(), 'error' => null]);
    }

    private function markSkipped(): void
    {
        DB::connection('pgvector')->table('const_court_queue')
            ->where('legal_id', $this->legalId)
            ->update(['status' => 'skipped']);
    }

    private function markFailed(string $reason): void
    {
        DB::connection('pgvector')->table('const_court_queue')
            ->where('legal_id', $this->legalId)
            ->update(['status' => 'failed', 'error' => mb_substr($reason, 0, 500)]);
    }
}
