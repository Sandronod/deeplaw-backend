<?php

namespace App\Jobs;

use App\Models\Law;
use App\Models\LawArticle;
use App\Services\AI\EmbedCacheService;
use App\Services\Matsne\ExternalSourceRateLimiter;
use App\Services\Matsne\FetchLockService;
use App\Services\Matsne\MatsneFetchService;
use App\Services\Matsne\MatsneHtmlParserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FetchMatsneLawJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 180;
    public int $backoff = 30;

    /**
     * Cache key that tracks the current law index version.
     * Incrementing this key busts all LawRetrieverService caches.
     */
    public const CACHE_INDEX_VERSION_KEY = 'law_index_version';

    public function __construct(
        public readonly int    $matsneId,
        public readonly string $lawName = '',
        public readonly bool   $forceRefresh = false,
    ) {}

    public function handle(
        MatsneFetchService        $fetcher,
        MatsneHtmlParserService   $parser,
        EmbedCacheService         $embedCache,
        FetchLockService          $lockService,
        ExternalSourceRateLimiter $rateLimiter,
    ): void {
        // ── 1. Acquire exclusive fetch lock ───────────────────────────────────
        $lock = $lockService->acquire($this->matsneId);

        if (!$lock) {
            Log::info('FetchMatsneLawJob: skipped — lock held', ['matsne_id' => $this->matsneId]);
            return;
        }

        try {
            // ── 2. Skip check (unless force refresh) ──────────────────────────
            if (!$this->forceRefresh) {
                $exists = DB::connection('pgvector')
                    ->table('laws')
                    ->where('matsne_id', (string) $this->matsneId)
                    ->exists();

                if ($exists) {
                    Log::info('FetchMatsneLawJob: already indexed', ['matsne_id' => $this->matsneId]);
                    return;
                }
            }

            // ── 3. Rate-limited fetch ─────────────────────────────────────────
            $html = $rateLimiter->throttle('matsne.gov.ge', function () use ($fetcher) {
                return $fetcher->fetchHtml($this->matsneId);
            });

            // ── 4. Parse ──────────────────────────────────────────────────────
            $parsed = $parser->parse($html, $this->matsneId);

            if (empty($parsed['articles'])) {
                Log::warning('FetchMatsneLawJob: no articles parsed', ['matsne_id' => $this->matsneId]);
                return;
            }

            // ── 5. Upsert law ─────────────────────────────────────────────────
            $law = Law::on('pgvector')->updateOrCreate(
                ['matsne_id' => (string) $this->matsneId],
                [
                    'title'      => $parsed['title'] ?: ($this->lawName ?: "კანონი #{$this->matsneId}"),
                    'category'   => 'კანონი',
                    'status'     => 'active',
                    'source_url' => "https://matsne.gov.ge/ka/document/view/{$this->matsneId}/0",
                ]
            );

            // ── 6. Create new version (keep old versions + articles intact) ───
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

            // ── 7. Embed + save new articles under new version ────────────────
            // Old articles are NOT deleted — they remain linked to their version.
            $texts = array_map(fn($a) => trim(
                ($a['article_num'] ? $a['article_num'] . '. ' : '') . $a['content']
            ), $parsed['articles']);

            $embeddings = $embedCache->embedBatch($texts);

            DB::connection('pgvector')->transaction(function () use ($law, $parsed, $embeddings, $versionId) {
                foreach ($parsed['articles'] as $i => $article) {
                    $record = LawArticle::create([
                        'law_id'         => $law->id,
                        'law_version_id' => $versionId,
                        'article_num'    => $article['article_num']   ?? null,
                        'article_title'  => $article['article_title'] ?? null,
                        'content'        => $article['content'],
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

            // ── 8. Bust law retrieval cache ───────────────────────────────────
            $this->invalidateCache();

            Log::info('FetchMatsneLawJob: done', [
                'matsne_id'     => $this->matsneId,
                'law_id'        => $law->id,
                'version_id'    => $versionId,
                'article_count' => count($parsed['articles']),
            ]);

        } finally {
            $lock->release();
            $lockService->unmarkQueued($this->matsneId);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('FetchMatsneLawJob: failed', [
            'matsne_id' => $this->matsneId,
            'error'     => $e->getMessage(),
            'attempt'   => $this->attempts(),
        ]);

        app(FetchLockService::class)->unmarkQueued($this->matsneId);
    }

    /**
     * Increment the global law index version counter.
     * LawRetrieverService includes this counter in its cache keys,
     * so incrementing here automatically invalidates all stale results.
     */
    private function invalidateCache(): void
    {
        Cache::increment(self::CACHE_INDEX_VERSION_KEY);

        Log::debug('FetchMatsneLawJob: cache busted', [
            'matsne_id'     => $this->matsneId,
            'index_version' => Cache::get(self::CACHE_INDEX_VERSION_KEY),
        ]);
    }
}
