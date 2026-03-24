<?php

namespace App\Services\AI;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HyDEService
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey  = config('openai.api_key');
        $this->model   = config('openai.chat_model', 'gpt-4.1');
        $this->baseUrl = config('openai.base_url', 'https://api.openai.com/v1');
    }

    /**
     * Generates a hypothetical court decision excerpt for the given legal query.
     * This "ideal answer" is used for embedding instead of the raw query,
     * dramatically improving vector search recall (HyDE technique).
     */
    public function generate(string $query): string
    {
        try {
            $response = Http::retry(3, 600, fn ($e) =>
                $e instanceof RequestException && in_array($e->response?->status(), [500, 502, 503, 529])
            )
                ->withToken($this->apiKey)
                ->timeout(20)
                ->post("{$this->baseUrl}/chat/completions", [
                    'model'       => $this->model,
                    'temperature' => 0,
                    'max_tokens'  => 300,
                    'messages'    => [
                        [
                            'role'    => 'system',
                            'content' => <<<PROMPT
შენ ხარ საქართველოს უზენაესი სასამართლოს გადაწყვეტილებების ექსპერტი.
მოცემული იურიდიული კითხვისთვის დაწერე მოკლე (200-250 სიტყვა) ჰიპოთეტური სასამართლო გადაწყვეტილების ამონარიდი,
რომელიც ამ კითხვას პასუხობს. გამოიყენე სასამართლო ენა და ტერმინოლოგია.
მხოლოდ ამონარიდი დააბრუნე, სხვა კომენტარი არ დაამატო.
PROMPT,
                        ],
                        [
                            'role'    => 'user',
                            'content' => $query,
                        ],
                    ],
                ]);

            if ($response->successful()) {
                $text = trim($response->json('choices.0.message.content') ?? '');
                if (!empty($text)) {
                    Log::debug('HyDE generated', ['query' => $query, 'length' => mb_strlen($text)]);
                    return $text;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('HyDE failed, falling back to raw query: ' . $e->getMessage());
        }

        return $query;
    }
}
