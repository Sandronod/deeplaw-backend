<?php

namespace App\Console\Commands;

use App\Jobs\FetchMatsneLawJob;
use App\Models\Law;
use App\Models\LawArticle;
use App\Services\AI\EmbedCacheService;
use App\Services\Matsne\ExternalSourceRateLimiter;
use App\Services\Matsne\MatsneFetchService;
use App\Services\Matsne\MatsneHtmlParserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncAllLawsCommand extends Command
{
    protected $signature = 'matsne:sync-all
                            {--force   : Re-fetch and re-embed even if content hash unchanged}
                            {--dry-run : Show what would be fetched without doing anything}
                            {--delay=  : Override random delay range in seconds (e.g. 3 = fixed 3s)}
                            {--limit=0 : Stop after N laws (0 = no limit, use 1-3 for testing)}
                            {--yes     : Skip confirmation prompt}';

    protected $description = 'Sync all laws from matsne_laws_map.json into pgvector (hash-based skip, chunked embed)';

    // ── Chunking ──────────────────────────────────────────────────────────────
    private const CHUNK_CHARS    = 750;   // ~1000 tokens
    private const CHUNK_OVERLAP  = 100;
    private const EMBED_BATCH    = 10;

    // ── Retry ─────────────────────────────────────────────────────────────────
    private const MAX_RETRIES    = 3;
    private const BACKOFF_BASE   = 5;    // seconds — doubles each retry

    // ── Rate limiting ─────────────────────────────────────────────────────────
    private const DELAY_MIN      = 8;    // seconds between requests
    private const DELAY_MAX      = 15;
    private const BATCH_PAUSE_N  = 50;   // pause every N laws
    private const BATCH_PAUSE_S  = 90;   // pause duration in seconds

    // ── Stats ─────────────────────────────────────────────────────────────────
    private int $synced  = 0;
    private int $skipped = 0;
    private int $failed  = 0;
    private array $failedNames = [];

    public function __construct(
        private readonly MatsneFetchService      $fetcher,
        private readonly MatsneHtmlParserService $parser,
        private readonly EmbedCacheService       $embedCache,
        private readonly ExternalSourceRateLimiter $rateLimiter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        ini_set('max_execution_time', 0);

        $map = $this->loadMap();
        if (empty($map)) {
            $this->error('matsne_laws_map.json is empty or missing.');
            return self::FAILURE;
        }

        $total    = count($map);
        $isDryRun = (bool) $this->option('dry-run');
        $isForce  = (bool) $this->option('force');

        // ── Already indexed ──────────────────────────────────────────────────
        $indexed = DB::connection('pgvector')
            ->table('laws')
            ->whereNotNull('matsne_id')
            ->pluck('content_hash', 'matsne_id')
            ->mapWithKeys(fn($hash, $id) => [(string) $id => $hash])
            ->all();

        $toFetch = $isForce
            ? $map
            : array_filter($map, fn($id) => !array_key_exists((string) $id, $indexed));

        $fetchCount = count($toFetch);
        $skipCount  = $total - $fetchCount;

        // ── Summary + confirm ────────────────────────────────────────────────
        $this->info("Matsne sync-all");
        $this->line("  Total in map : {$total}");
        $this->line("  Already indexed (will skip): {$skipCount}");
        $this->line("  To fetch : {$fetchCount}" . ($isForce ? ' (--force: includes re-fetches)' : ''));

        if ($isDryRun) {
            $this->warn('DRY RUN — nothing will be written.');
            foreach ($toFetch as $name => $id) {
                $this->line("  [would fetch] {$name} (matsne_id={$id})");
            }
            return self::SUCCESS;
        }

        if ($fetchCount === 0) {
            $this->info('Nothing to fetch. All laws are up to date.');
            return self::SUCCESS;
        }

        if (!$this->option('yes') && !$this->confirm("Fetch {$fetchCount} laws now?")) {
            return self::SUCCESS;
        }

        $this->info('Starting sync...');
        Log::channel('matsne')->info('matsne:sync-all started', [
            'total'      => $total,
            'to_fetch'   => $fetchCount,
            'force'      => $isForce,
        ]);

        $bar = $this->output->createProgressBar($fetchCount);
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $limit     = (int) $this->option('limit');
        $processed = 0;
        $bar->start();

        foreach ($toFetch as $lawName => $matsneId) {
            if ($limit > 0 && ($this->synced + $this->skipped + $this->failed) >= $limit) {
                break;
            }

            $bar->setMessage(mb_substr($lawName, 0, 50));
            $this->syncOne((int) $matsneId, $lawName, $isForce, $indexed);
            $bar->advance();
            $processed++;

            // Batch pause every N laws
            if ($processed % self::BATCH_PAUSE_N === 0) {
                $bar->setMessage("--- პაუზა " . self::BATCH_PAUSE_S . "წმ ({$processed} კანონი დამუშავდა) ---");
                Log::channel('matsne')->info('batch pause', ['processed' => $processed]);
                sleep(self::BATCH_PAUSE_S);
            }
        }

        $bar->finish();
        $this->newLine(2);

        // ── Final report ─────────────────────────────────────────────────────
        $this->info('Sync complete.');
        $this->line("  ✓ Synced  : {$this->synced}");
        $this->line("  ↷ Skipped : {$this->skipped} (hash unchanged)");
        $this->line("  ✗ Failed  : {$this->failed}");

        if (!empty($this->failedNames)) {
            $this->warn('Failed laws:');
            foreach ($this->failedNames as $entry) {
                $this->line("    - {$entry}");
            }
        }

        Log::channel('matsne')->info('matsne:sync-all finished', [
            'synced'  => $this->synced,
            'skipped' => $this->skipped,
            'failed'  => $this->failed,
        ]);

        return self::SUCCESS;
    }

    // ── Per-law sync ──────────────────────────────────────────────────────────

    private function syncOne(int $matsneId, string $lawName, bool $force, array $indexed): void
    {
        // Random delay 8–15s between requests (Matsne IP block protection)
        $delay = $this->option('delay') !== null
            ? (int) $this->option('delay')
            : rand(self::DELAY_MIN, self::DELAY_MAX);
        sleep($delay);

        // ── Fetch with retry + exponential backoff ────────────────────────────
        $html = $this->fetchWithRetry($matsneId, $lawName);
        if ($html === null) {
            $this->failed++;
            $this->failedNames[] = "{$lawName} (matsne_id={$matsneId})";
            return;
        }

        // ── Hash check — always applies, even with --force ───────────────────
        $hash = md5($html);
        if (array_key_exists((string) $matsneId, $indexed) && $indexed[(string) $matsneId] === $hash) {
            $this->skipped++;
            Log::channel('matsne')->debug('skip (hash unchanged)', ['matsne_id' => $matsneId]);
            return;
        }

        // ── Parse ─────────────────────────────────────────────────────────────
        try {
            $parsed = $this->parser->parse($html, $matsneId);
        } catch (Throwable $e) {
            $this->failed++;
            $this->failedNames[] = "{$lawName} (parse error: {$e->getMessage()})";
            Log::channel('matsne')->error('parse failed', ['matsne_id' => $matsneId, 'error' => $e->getMessage()]);
            return;
        }

        if (empty($parsed['articles'])) {
            $this->failed++;
            $this->failedNames[] = "{$lawName} (no articles parsed)";
            Log::channel('matsne')->warning('no articles', ['matsne_id' => $matsneId]);
            return;
        }

        // ── Hash check on parsed content (stable, no dynamic HTML noise) ─────
        $hash = md5(json_encode($parsed['articles']));
        if (array_key_exists((string) $matsneId, $indexed) && $indexed[(string) $matsneId] === $hash) {
            $this->skipped++;
            Log::channel('matsne')->debug('skip (hash unchanged)', ['matsne_id' => $matsneId]);
            return;
        }

        // ── Chunk articles ────────────────────────────────────────────────────
        $chunks = $this->chunkArticles($parsed['articles']);

        // ── Embed ─────────────────────────────────────────────────────────────
        $texts      = array_map(fn($c) => trim(($c['article_num'] ? $c['article_num'] . '. ' : '') . $c['content']), $chunks);
        $embeddings = $this->embedWithRetry($texts, $matsneId);
        if ($embeddings === null) {
            $this->failed++;
            $this->failedNames[] = "{$lawName} (embed failed)";
            return;
        }

        // ── Save to DB ────────────────────────────────────────────────────────
        try {
            $this->saveToDb($matsneId, $lawName, $parsed, $chunks, $embeddings, $hash);
        } catch (Throwable $e) {
            $this->failed++;
            $this->failedNames[] = "{$lawName} (db error: {$e->getMessage()})";
            Log::channel('matsne')->error('db save failed', ['matsne_id' => $matsneId, 'error' => $e->getMessage()]);
            return;
        }

        $this->synced++;
        Log::channel('matsne')->info('synced', [
            'matsne_id' => $matsneId,
            'title'     => $parsed['title'],
            'chunks'    => count($chunks),
        ]);
    }

    // ── Fetch with retry ──────────────────────────────────────────────────────

    private function fetchWithRetry(int $matsneId, string $lawName): ?string
    {
        $attempt = 0;

        while ($attempt < self::MAX_RETRIES) {
            try {
                $html = $this->rateLimiter->throttle('matsne.gov.ge', function () use ($matsneId) {
                    return $this->fetcher->fetchHtml($matsneId);
                });

                return $html;

            } catch (Throwable $e) {
                $attempt++;
                $message = $e->getMessage();

                // Permanent errors — don't retry
                if (str_contains($message, 'HTTP 403') || str_contains($message, 'HTTP 404')) {
                    Log::channel('matsne')->warning('permanent error, skipping', [
                        'matsne_id' => $matsneId,
                        'error'     => $message,
                    ]);
                    return null;
                }

                if ($attempt >= self::MAX_RETRIES) {
                    Log::channel('matsne')->error('fetch failed after retries', [
                        'matsne_id' => $matsneId,
                        'attempts'  => $attempt,
                        'error'     => $message,
                    ]);
                    return null;
                }

                // 429 → wait longer before retry
                $backoff = str_contains($message, '429')
                    ? 60
                    : self::BACKOFF_BASE * (2 ** ($attempt - 1));

                Log::channel('matsne')->warning('fetch retry', [
                    'matsne_id' => $matsneId,
                    'attempt'   => $attempt,
                    'backoff_s' => $backoff,
                    'error'     => $message,
                ]);

                sleep($backoff);
            }
        }

        return null;
    }

    // ── Embed with retry ──────────────────────────────────────────────────────

    private function embedWithRetry(array $texts, int $matsneId): ?array
    {
        $attempt = 0;

        while ($attempt < self::MAX_RETRIES) {
            try {
                return $this->embedCache->embedBatch($texts);
            } catch (Throwable $e) {
                $attempt++;
                if ($attempt >= self::MAX_RETRIES) {
                    Log::channel('matsne')->error('embed failed after retries', [
                        'matsne_id' => $matsneId,
                        'error'     => $e->getMessage(),
                    ]);
                    return null;
                }

                $backoff = self::BACKOFF_BASE * (2 ** ($attempt - 1));
                sleep($backoff);
            }
        }

        return null;
    }

    // ── Save to DB ────────────────────────────────────────────────────────────

    private function saveToDb(
        int    $matsneId,
        string $lawName,
        array  $parsed,
        array  $chunks,
        array  $embeddings,
        string $hash,
    ): void {
        DB::connection('pgvector')->transaction(function () use (
            $matsneId, $lawName, $parsed, $chunks, $embeddings, $hash
        ) {
            // Upsert law
            $law = Law::on('pgvector')->updateOrCreate(
                ['matsne_id' => (string) $matsneId],
                [
                    'title'        => $parsed['title'] ?: $lawName,
                    'category'     => 'კანონი',
                    'status'       => 'active',
                    'source_url'   => "https://matsne.gov.ge/ka/document/view/{$matsneId}/0",
                    'content_hash' => $hash,
                ]
            );

            // New version
            DB::connection('pgvector')
                ->table('law_versions')
                ->where('law_id', $law->id)
                ->update(['is_current' => false]);

            $versionId = DB::connection('pgvector')->table('law_versions')->insertGetId([
                'law_id'        => $law->id,
                'version_date'  => now()->toDateString(),
                'version_label' => now()->format('Y-m-d') . ' ვერსია',
                'is_current'    => true,
                'fetched_at'    => now(),
            ]);

            DB::connection('pgvector')
                ->table('laws')
                ->where('id', $law->id)
                ->update(['current_version_id' => $versionId]);

            // Save chunks + embeddings
            foreach ($chunks as $i => $chunk) {
                $record = LawArticle::create([
                    'law_id'         => $law->id,
                    'law_version_id' => $versionId,
                    'article_num'    => $chunk['article_num']   ?? null,
                    'article_title'  => $chunk['article_title'] ?? null,
                    'content'        => $chunk['content'],
                    'chunk_index'    => $i,
                ]);

                if (!empty($embeddings[$i])) {
                    $vec = '[' . implode(',', $embeddings[$i]) . ']';
                    DB::connection('pgvector')->statement(
                        'UPDATE law_articles SET embedding = :emb::vector WHERE id = :id',
                        ['emb' => $vec, 'id' => $record->id]
                    );
                }
            }
        });

        // Cache bust only when data actually changed
        Cache::increment(FetchMatsneLawJob::CACHE_INDEX_VERSION_KEY);
    }

    // ── Chunking ──────────────────────────────────────────────────────────────

    /**
     * Split articles into embedding-ready chunks.
     * Short articles (< CHUNK_CHARS) → 1 chunk.
     * Long articles → overlapping sub-chunks.
     */
    private function chunkArticles(array $articles): array
    {
        $result = [];

        foreach ($articles as $article) {
            $content = $article['content'] ?? '';

            if (mb_strlen($content) <= self::CHUNK_CHARS) {
                $result[] = $article;
                continue;
            }

            // Split long article into overlapping chunks
            $step  = self::CHUNK_CHARS - self::CHUNK_OVERLAP;
            $len   = mb_strlen($content);
            $start = 0;
            $part  = 1;

            while ($start < $len) {
                $slice = mb_substr($content, $start, self::CHUNK_CHARS);
                $result[] = [
                    'article_num'   => ($article['article_num'] ?? '') . ($part > 1 ? ".{$part}" : ''),
                    'article_title' => $article['article_title'] ?? '',
                    'content'       => trim($slice),
                ];
                $start += $step;
                $part++;
            }
        }

        return $result;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function loadMap(): array
    {
        $path = database_path('seeds/matsne_laws_map.json');
        if (!file_exists($path)) {
            return [];
        }
        return json_decode(file_get_contents($path), true) ?? [];
    }
}
