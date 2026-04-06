<?php

namespace App\Jobs;

use App\Contracts\EmbeddingServiceInterface;
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
 * Scheduled bulk sync for the ECHR seed corpus.
 *
 * Seed strategy (importance 1+2 only, max 50 per topic):
 *  - All cases against Georgia
 *  - Top Article 6 (fair trial) cases
 *  - Top Article 8 (privacy) cases
 *  - Top Article 10 (expression) cases
 *
 * Runs weekly via routes/console.php schedule.
 */
class SyncTopEchrTopicsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 600; // 10 minutes

    /** Max cases to fetch/update per topic per run */
    private const PER_TOPIC_LIMIT = 30;

    /** Topics to sync: [type => value] */
    private const TOPICS = [
        'georgia'  => null,      // all Georgia cases
        'article'  => '6',
        'article'  => '8',
        'article'  => '10',
        'article'  => '3',
    ];

    public function handle(
        HudocSearchService    $search,
        HudocFetchService     $fetcher,
        HudocParserService    $parser,
        EchrRepositoryService $repo,
        EmbeddingServiceInterface $embedder,
    ): void {
        Log::info('SyncTopEchrTopicsJob: starting bulk sync');

        $totalFetched = 0;
        $totalNew     = 0;

        // Georgia cases (importance 1+2)
        $totalNew += $this->syncTopic(
            'georgia_seed',
            fn() => $search->searchGeorgiaSeedCases(self::PER_TOPIC_LIMIT),
            $fetcher, $parser, $repo, $embedder, $totalFetched
        );

        // Top Article cases (importance 1+2)
        foreach (['6', '8', '10', '3'] as $article) {
            $totalNew += $this->syncTopic(
                "article_{$article}_seed",
                fn() => $search->searchArticleSeedCases($article, self::PER_TOPIC_LIMIT),
                $fetcher, $parser, $repo, $embedder, $totalFetched
            );
        }

        if ($totalNew > 0) {
            EchrRetrieverService::invalidateCache();
        }

        Log::info('SyncTopEchrTopicsJob: completed', [
            'total_fetched' => $totalFetched,
            'total_new'     => $totalNew,
        ]);

        $repo->log('bulk', 'scheduled_seed', [
            'fetched' => $totalFetched,
            'new'     => $totalNew,
        ]);
    }

    private function syncTopic(
        string                $topicKey,
        callable              $searchFn,
        HudocFetchService     $fetcher,
        HudocParserService    $parser,
        EchrRepositoryService $repo,
        EmbeddingServiceInterface $embedder,
        int                   &$totalFetched,
    ): int {
        $columns = $searchFn();
        $new     = 0;

        foreach ($columns as $col) {
            $itemId = $col['itemid'] ?? null;
            if (!$itemId) continue;

            $existing = $repo->findByItemId($itemId);
            // Skip if synced within last 30 days
            if ($existing && $existing->last_synced_at?->diffInDays(now()) < 30) {
                continue;
            }

            try {
                $fullText = $fetcher->fetchText($itemId);
                $parsed   = $parser->parse($col, $fullText);
                $result   = $repo->upsert($parsed);

                if ($result['is_new']) {
                    $new++;
                }

                // Embed un-embedded paragraphs
                foreach ($result['case']->paragraphs()->whereNull('embedding')->get() as $para) {
                    try {
                        $embedding = $embedder->embed($para->content);
                        $repo->embedParagraph($para->id, $embedding);
                    } catch (\Throwable $e) {
                        Log::warning('SyncTopEchrTopicsJob: embedding failed', [
                            'para_id' => $para->id,
                            'error'   => $e->getMessage(),
                        ]);
                    }
                }

                $totalFetched++;

            } catch (\Throwable $e) {
                Log::warning('SyncTopEchrTopicsJob: case failed', [
                    'itemid' => $itemId,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        Log::debug("SyncTopEchrTopicsJob: topic={$topicKey}", [
            'columns' => count($columns),
            'new'     => $new,
        ]);

        return $new;
    }
}
