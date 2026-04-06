<?php

namespace App\Jobs;

use App\Contracts\EmbeddingServiceInterface as EmbeddingInterface;
use App\Services\Echr\EchrFetchLockService;
use App\Services\Echr\EchrRepositoryService;
use App\Services\Echr\EchrRetrieverService;
use App\Services\Echr\HudocFetchService;
use App\Services\Echr\HudocParserService;
use App\Services\Echr\HudocSearchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * On-demand HUDOC fetch job.
 *
 * Triggered when EchrRetrieverService finds no local results.
 * Searches HUDOC, fetches and embeds up to MAX_CASES_PER_FETCH new cases,
 * then increments the ECHR index version (cache bust).
 */
class FetchHudocCaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 120;

    private const MAX_CASES_PER_FETCH = 8;

    public function __construct(
        public readonly string  $query,
        public readonly ?string $echrArticle  = null,
        public readonly bool    $georgiaFirst = false,
        public readonly ?string $fetchKey     = null,
    ) {}

    public function handle(
        HudocSearchService    $search,
        HudocFetchService     $fetcher,
        HudocParserService    $parser,
        EchrRepositoryService $repo,
        EchrFetchLockService  $lock,
        EmbeddingInterface    $embedder,
    ): void {
        $lockKey = $this->fetchKey ?? md5('echr:' . $this->query);

        // Acquire job-level lock
        $acquiredLock = $lock->acquire($lockKey);
        if (!$acquiredLock) {
            Log::debug('FetchHudocCaseJob: lock busy, skipping', ['query' => $this->query]);
            return;
        }

        try {
            $columns = $this->fetchSearchResults($search);

            Log::debug('FetchHudocCaseJob: search returned', [
                'count' => count($columns),
                'query' => $this->query,
            ]);

            $fetched = 0;
            $new     = 0;

            foreach (array_slice($columns, 0, self::MAX_CASES_PER_FETCH) as $col) {
                $itemId = $col['itemid'] ?? null;
                if (!$itemId) continue;

                // Skip if already stored and recently synced (within 7 days)
                $existing = $repo->findByItemId($itemId);
                if ($existing && $existing->last_synced_at?->diffInDays(now()) < 7) {
                    continue;
                }

                $fullText = $fetcher->fetchText($itemId);
                $parsed   = $parser->parse($col, $fullText);
                $result   = $repo->upsert($parsed);

                if ($result['is_new']) {
                    $new++;
                }

                // Embed paragraphs
                $this->embedParagraphs($result['case'], $repo, $embedder, $parser, $fullText);

                $fetched++;
            }

            // Bump cache version so EchrRetrieverService invalidates stale results
            if ($new > 0) {
                EchrRetrieverService::invalidateCache();
            }

            $repo->log('on_demand', $this->query, [
                'fetched' => $fetched,
                'new'     => $new,
            ]);

            Log::info('FetchHudocCaseJob: completed', [
                'query'   => $this->query,
                'fetched' => $fetched,
                'new'     => $new,
            ]);

        } catch (\Throwable $e) {
            Log::error('FetchHudocCaseJob: exception', [
                'query' => $this->query,
                'error' => $e->getMessage(),
            ]);

            $repo->log('on_demand', $this->query, ['fetched' => 0, 'new' => 0], 'error', $e->getMessage());

        } finally {
            $acquiredLock->release();
            $lock->unmarkQueued($lockKey);
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function fetchSearchResults(HudocSearchService $search): array
    {
        if ($this->echrArticle && $this->georgiaFirst) {
            $results = $search->searchGeorgiaRelated("Article {$this->echrArticle} {$this->query}", 10);
        } elseif ($this->echrArticle) {
            $results = $search->searchByArticle($this->echrArticle, 10);
        } elseif ($this->georgiaFirst) {
            $results = $search->searchGeorgiaRelated($this->query, 10);
        } else {
            $results = $search->searchByKeyword($this->query, 10);
        }

        return $results;
    }

    private function embedParagraphs(
        \App\Models\EchrCase  $case,
        EchrRepositoryService $repo,
        EmbeddingInterface    $embedder,
        HudocParserService    $parser,
        ?string               $fullText,
    ): void {
        if (empty($fullText)) return;

        $paragraphs = $case->paragraphs()->whereNull('embedding')->get();

        foreach ($paragraphs as $para) {
            try {
                $embedding = $embedder->embed($para->content);
                $repo->embedParagraph($para->id, $embedding);
            } catch (\Throwable $e) {
                Log::warning('FetchHudocCaseJob: embedding failed', [
                    'paragraph_id' => $para->id,
                    'error'        => $e->getMessage(),
                ]);
            }
        }
    }
}
