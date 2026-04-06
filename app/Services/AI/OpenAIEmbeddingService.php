<?php

namespace App\Services\AI;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAIEmbeddingService implements \App\Contracts\EmbeddingServiceInterface
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private int    $timeout;

    public function __construct()
    {
        $this->apiKey  = config('openai.api_key');
        $this->model   = config('openai.embedding_model', 'text-embedding-3-large');
        $this->baseUrl = config('openai.base_url', 'https://api.openai.com/v1');
        $this->timeout = config('openai.timeout', 60);
    }

    /**
     * Returns embedding float array for the given text.
     *
     * @throws RuntimeException
     */
    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0];
    }

    /**
     * Embeds multiple texts in a single API call (more efficient than calling embed() N times).
     * OpenAI returns results indexed by input order.
     *
     * @param  string[]  $texts
     * @return array[]   Indexed 0..N-1, each element is a float[] embedding
     *
     * @throws RuntimeException
     */
    public function embedBatch(array $texts): array
    {
        $sanitized = array_map(fn($t) => $this->sanitize($t), array_values($texts));

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout($this->timeout)
                ->post("{$this->baseUrl}/embeddings", [
                    'model' => $this->model,
                    'input' => count($sanitized) === 1 ? $sanitized[0] : $sanitized,
                ]);

            if ($response->failed()) {
                throw new RuntimeException(
                    'OpenAI Embedding API error: ' . $response->status() . ' — ' . $response->body()
                );
            }

            $data = $response->json();

            if (empty($data['data'])) {
                throw new RuntimeException('OpenAI returned empty embedding.');
            }

            // OpenAI guarantees data[].index matches input order
            $embeddings = [];
            foreach ($data['data'] as $item) {
                $embeddings[$item['index']] = $item['embedding'];
            }
            ksort($embeddings);

            return array_values($embeddings);

        } catch (RequestException $e) {
            throw new RuntimeException('OpenAI Embedding request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function sanitize(string $text): string
    {
        return mb_substr(trim($text), 0, 8000);
    }
}
