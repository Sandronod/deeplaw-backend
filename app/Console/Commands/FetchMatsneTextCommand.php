<?php

namespace App\Console\Commands;

use App\Services\Matsne\MatsneFetchService;
use App\Services\Matsne\MatsneHtmlParserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 1 of 2-phase Matsne ingestion.
 * Fetches HTML, parses full text, saves to matsne_documents.content.
 * No Ollama/embedding calls — safe to run alongside other embedding jobs.
 *
 * Usage:
 *   php artisan matsne:fetch-text
 *   php artisan matsne:fetch-text --workers=3   (open 3 terminals, run in each)
 *   php artisan matsne:fetch-text --limit=1000
 *   php artisan matsne:fetch-text --retry-failed
 */
class FetchMatsneTextCommand extends Command
{
    protected $signature = 'matsne:fetch-text
        {--limit=0          : Max documents to process (0 = all pending)}
        {--delay=1          : Seconds between requests (rate limiting)}
        {--retry-failed     : Also retry previously failed documents}';

    protected $description = 'Phase 1: Fetch HTML and save full text to matsne_documents (no embedding)';

    private const BATCH = 200;

    public function handle(
        MatsneFetchService      $fetcher,
        MatsneHtmlParserService $parser,
    ): int {
        ini_set('memory_limit', '512M');

        $limit       = (int) $this->option('limit');
        $delay       = (int) $this->option('delay');
        $retryFailed = $this->option('retry-failed');

        if ($retryFailed) {
            DB::connection('pgvector')
                ->table('matsne_doc_queue')
                ->where('status', 'failed')
                ->update(['status' => 'pending', 'error' => null]);
        }

        $query = DB::connection('pgvector')
            ->table('matsne_doc_queue')
            ->where('status', 'pending')
            ->orderBy('matsne_id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No pending documents.');
            $this->printStats();
            return 0;
        }

        $this->info("Processing {$total} documents (fetch + parse only, no embedding)...");

        $bar       = $this->output->createProgressBar($total);
        $done      = 0;
        $failed    = 0;
        $skipped   = 0;

        $query->chunk(self::BATCH, function ($rows) use (
            $fetcher, $parser, $bar, $delay, &$done, &$failed, &$skipped
        ) {
            foreach ($rows as $row) {
                // Claim the row atomically
                $claimed = DB::connection('pgvector')->select(
                    'UPDATE matsne_doc_queue SET status = ? WHERE matsne_id = ? AND status = ? RETURNING matsne_id',
                    ['processing', $row->matsne_id, 'pending']
                );

                if (empty($claimed)) {
                    $skipped++;
                    $bar->advance();
                    continue;
                }

                try {
                    $this->processOne($row->matsne_id, $fetcher, $parser);
                    $done++;
                } catch (\Throwable $e) {
                    $failed++;
                    Log::warning('matsne:fetch-text failed', [
                        'matsne_id' => $row->matsne_id,
                        'error'     => $e->getMessage(),
                    ]);
                    DB::connection('pgvector')
                        ->table('matsne_doc_queue')
                        ->where('matsne_id', $row->matsne_id)
                        ->update([
                            'status' => 'failed',
                            'error'  => mb_substr($e->getMessage(), 0, 500),
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
        $this->info("Done: {$done} | Failed: {$failed} | Skipped: {$skipped}");
        $this->printStats();

        return 0;
    }

    private function processOne(int $matsneId, MatsneFetchService $fetcher, MatsneHtmlParserService $parser): void
    {
        $html = $fetcher->fetchHtml($matsneId);

        if (empty($html) || mb_strlen($html) < 500) {
            throw new \RuntimeException('Empty HTML');
        }

        $parsed   = $parser->parse($html, $matsneId);
        $articles = $parsed['articles'] ?? [];
        $meta     = $parsed['meta'] ?? [];

        if (empty($articles)) {
            // Mark as text_ready with empty content — embed phase will skip
            DB::connection('pgvector')
                ->table('matsne_doc_queue')
                ->where('matsne_id', $matsneId)
                ->update(['status' => 'text_ready', 'error' => 'no_articles']);
            return;
        }

        $fullText = collect($articles)
            ->map(fn($a) => trim(($a['article_title'] ?? '') . "\n" . ($a['content'] ?? '')))
            ->filter()
            ->implode("\n\n");

        if (mb_strlen($fullText) < 10) {
            throw new \RuntimeException('Text too short after parse');
        }

        $hash = md5($fullText);

        $queueRow = DB::connection('pgvector')
            ->table('matsne_doc_queue')
            ->where('matsne_id', $matsneId)
            ->first();

        $title   = $parsed['title'] ?? $queueRow?->title;
        $docType = $meta['doc_type'] ?? $queueRow?->doc_type;

        $fromYear = isset($meta['effective_from'])
            ? (int) substr($meta['effective_from'], 0, 4)
            : (isset($meta['signing_date']) ? (int) substr($meta['signing_date'], 0, 4) : null);
        $toYear = isset($meta['effective_to'])
            ? (int) substr($meta['effective_to'], 0, 4)
            : null;

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
            'updated_at'     => now(),
        ];

        $existing = DB::connection('pgvector')
            ->table('matsne_documents')
            ->where('matsne_id', $matsneId)
            ->first();

        if ($existing) {
            DB::connection('pgvector')
                ->table('matsne_documents')
                ->where('matsne_id', $matsneId)
                ->update($docData);
        } else {
            DB::connection('pgvector')
                ->table('matsne_documents')
                ->insert(array_merge($docData, [
                    'matsne_id'  => $matsneId,
                    'created_at' => now(),
                ]));
        }

        DB::connection('pgvector')
            ->table('matsne_doc_queue')
            ->where('matsne_id', $matsneId)
            ->update(['status' => 'text_ready', 'error' => null]);
    }

    private function printStats(): void
    {
        $counts = DB::connection('pgvector')
            ->table('matsne_doc_queue')
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->orderBy('status')
            ->pluck('cnt', 'status');

        $this->table(
            ['Status', 'Count'],
            $counts->map(fn($cnt, $status) => [$status, number_format($cnt)])->values()
        );
    }
}
