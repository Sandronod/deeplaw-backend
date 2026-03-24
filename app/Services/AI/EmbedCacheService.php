<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Caching layer around OpenAIEmbeddingService.
 *
 * - Single embed():  full cache hit/miss
 * - Batch embedBatch(): per-text cache; only uncached texts hit the API
 *
 * TTL: 24h (embeddings are deterministic for same model+text)
 * Key: md5(text + model) to invalidate automatically on model change
 */
class EmbedCacheService
{
    private const TTL_SECONDS = 86400; // 24 hours

    public function __construct(
        private readonly OpenAIEmbeddingService $embedder,
    ) {}

    /**
     * Single embedding with cache.
     */
    public function embed(string $text): array
    {
        return Cache::remember(
            $this->cacheKey($text),
            self::TTL_SECONDS,
            fn() => $this->embedder->embed($text),
        );
    }

    /**
     * Batch embedding with per-text cache.
     * Texts already cached are returned immediately; only uncached texts
     * go to OpenAI in one batched call.
     *
     * @param  string[]  $texts
     * @return array[]   Float embeddings, indexed same order as input
     */
    public function embedBatch(array $texts): array
    {
        $texts    = array_values($texts);
        $results  = [];
        $toFetch  = []; // originalIndex => text
        $keyMap   = []; // originalIndex => cacheKey

        foreach ($texts as $i => $text) {
            $key           = $this->cacheKey($text);
            $keyMap[$i]    = $key;
            $cached        = Cache::get($key);

            if ($cached !== null) {
                $results[$i] = $cached;
            } else {
                $toFetch[$i] = $text;
            }
        }

        if (!empty($toFetch)) {
            Log::debug('EmbedCache: miss, fetching', ['count' => count($toFetch)]);

            $fetched = $this->embedder->embedBatch(array_values($toFetch));
            $indices = array_keys($toFetch);

            foreach ($fetched as $batchPos => $embedding) {
                $originalIdx = $indices[$batchPos];
                Cache::put($keyMap[$originalIdx], $embedding, self::TTL_SECONDS);
                $results[$originalIdx] = $embedding;
            }
        }

        ksort($results);
        return array_values($results);
    }

    private function cacheKey(string $text): string
    {
        return 'embed_' . md5($text . config('openai.embedding_model', 'text-embedding-3-large'));
    }
}
