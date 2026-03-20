<?php

namespace App\Services\AI;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAIEmbeddingService
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
        $text = $this->sanitize($text);

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout($this->timeout)
                ->post("{$this->baseUrl}/embeddings", [
                    'model' => $this->model,
                    'input' => $text,
                ]);

            if ($response->failed()) {
                throw new RuntimeException(
                    'OpenAI Embedding API error: ' . $response->status() . ' — ' . $response->body()
                );
            }

            $data = $response->json();

            if (empty($data['data'][0]['embedding'])) {
                throw new RuntimeException('OpenAI returned empty embedding.');
            }

            return $data['data'][0]['embedding'];

        } catch (RequestException $e) {
            throw new RuntimeException('OpenAI Embedding request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    private function sanitize(string $text): string
    {
        // Truncate to ~8000 chars to stay within token limits
        return mb_substr(trim($text), 0, 8000);
    }
}
