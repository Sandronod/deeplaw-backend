<?php

namespace App\Services\AI;

use App\Contracts\EmbeddingServiceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;


class OllamaEmbeddingService implements EmbeddingServiceInterface
{
    private string $baseUrl;
    private string $model;
    private int    $timeout;
    private int    $maxChars;

    private bool $forceCpu;

    public function __construct()
    {
        $this->baseUrl  = config('ollama.base_url', 'http://localhost:11434');
        $this->model    = config('ollama.embedding_model', 'bge-m3');
        $this->timeout  = config('ollama.timeout', 120);
        $this->maxChars = 8000;
        $this->forceCpu = config('ollama.force_cpu', false);
    }

    /**
     * Embed a single text.
     */
    public function embed(string $text): array
    {
        return $this->embedBatch([$text])[0];
    }

    /**
     * Embed multiple texts — Ollama /api/embed supports batch input.
     *
     * @param  string[]  $texts
     * @return array[]
     */
    public function embedBatch(array $texts): array
    {
        $results = [];
        foreach ($texts as $text) {
            $results[] = $this->embedSingle($this->sanitize($text));
        }
        return $results;
    }

    private function embedSingle(string $text): array
    {
        // CPU-only mode (query-time: predictable ~3s, no NaN)
        if ($this->forceCpu) {
            $vec = $this->callOllama($text, useGpu: false);
            Log::debug('OllamaEmbedding: CPU-only', [
                'model' => $this->model,
                'dims'  => count($vec),
            ]);
            return $vec;
        }

        // Try GPU first (fast)
        try {
            $vec = $this->callOllama($text, useGpu: true);
            Log::debug('OllamaEmbedding: GPU success', [
                'model'  => $this->model,
                'length' => mb_strlen($text),
                'dims'   => count($vec),
            ]);
            return $vec;
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'NaN')) {
                throw $e;
            }
        }

        // GPU NaN → fallback to CPU (reliable)
        Log::debug('OllamaEmbedding: GPU NaN → CPU fallback', [
            'model'  => $this->model,
            'length' => mb_strlen($text),
        ]);

        $vec = $this->callOllama($text, useGpu: false);
        Log::debug('OllamaEmbedding: CPU success', [
            'model' => $this->model,
            'dims'  => count($vec),
        ]);
        return $vec;
    }

    private function callOllama(string $text, bool $useGpu = true): array
    {
        $payload = [
            'model' => $this->model,
            'input' => $text,
        ];

        if (!$useGpu) {
            $payload['options'] = ['num_gpu' => 0];
        }

        try {
            $response = Http::timeout($this->timeout)
                ->post("{$this->baseUrl}/api/embed", $payload);

            if ($response->failed()) {
                throw new RuntimeException(
                    'Ollama Embedding error: ' . $response->status() . ' — ' . $response->body()
                );
            }

            $data = $response->json();

            if (empty($data['embeddings'][0])) {
                throw new RuntimeException('Ollama returned empty embedding.');
            }

            return $data['embeddings'][0];

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new RuntimeException(
                'Ollama connection failed — is Ollama running? ' . $e->getMessage(), 0, $e
            );
        }
    }

    private function sanitize(string $text): string
    {
        // Normalize line endings to \n
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // Collapse multiple newlines into single newline
        $text = preg_replace('/\n+/', "\n", $text);
        // Collapse multiple spaces/tabs into one space
        $text = preg_replace('/[ \t]+/', ' ', $text);
        // Remove control characters except \n
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        return mb_substr(trim($text), 0, $this->maxChars);
    }
}
