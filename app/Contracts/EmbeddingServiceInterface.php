<?php

namespace App\Contracts;

interface EmbeddingServiceInterface
{
    /**
     * Embed a single text. Returns float array, or empty array if provider
     * does not support vector embeddings (e.g. Gemini in keyword-only mode).
     */
    public function embed(string $text): array;

    /**
     * Embed multiple texts in one batch. Returns array of float arrays.
     */
    public function embedBatch(array $texts): array;
}
