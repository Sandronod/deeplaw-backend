<?php

namespace App\Services\AI;

use App\Contracts\EmbeddingServiceInterface;

/**
 * Gemini provider — no vector embeddings in keyword-only mode.
 * Returns empty arrays so retrieval falls back to keyword/metadata search.
 */
class GeminiEmbeddingService implements EmbeddingServiceInterface
{
    public function embed(string $text): array
    {
        return [];
    }

    public function embedBatch(array $texts): array
    {
        return array_fill(0, count($texts), []);
    }
}
