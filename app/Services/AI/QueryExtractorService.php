<?php

namespace App\Services\AI;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QueryExtractorService
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey  = config('openai.api_key');
        // Intentionally uses extraction_model (mini) — simple keyword task, gpt-4.1 is overkill
        $this->model   = config('openai.extraction_model', 'gpt-4.1-mini');
        $this->baseUrl = config('openai.base_url', 'https://api.openai.com/v1');
    }

    /**
     * Extracts clean legal search terms from user message.
     *
     * "მიპოვე და შემიჯამე სახელმწიფო კომპენსაციის შესახებ გადაწყვეტილება"
     *   → "სახელმწიფო კომპენსაცია"
     */
    public function extract(string $userMessage): string
    {
        try {
            $response = Http::retry(3, 600, fn ($e) =>
                $e instanceof RequestException && in_array($e->response?->status(), [500, 502, 503, 529])
            )
                ->withToken($this->apiKey)
                ->timeout(15)
                ->post("{$this->baseUrl}/chat/completions", [
                    'model'       => $this->model,
                    'temperature' => 0,
                    'max_tokens'  => 120,
                    'messages'    => [
                        [
                            'role'    => 'system',
                            'content' => <<<PROMPT
ამოიღე სამართლებრივი საძიებო ტერმინები.

წესები:
- ამოიღე task სიტყვები: "მიპოვე", "შემიჯამე", "განმარტე", "find", "summarize", "გთხოვ"
- ამოიღე ზოგადი სიტყვები: "გადაწყვეტილება", "სასამართლო", "დავა", "საქმე"
- შეინახე: სამართლებრივი თემა, ტერმინი, case number, სახელები (მოსამართლე, მხარე, კომპანია)
- "მოსამართლე X" → შეინახე "X"
- დააბრუნე მხოლოდ ტერმინები, კომენტარი არ დაამატო
PROMPT,
                        ],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                ]);

            $extracted = trim($response->json('choices.0.message.content') ?? '');

            if (!empty($extracted)) {
                Log::debug('QueryExtractor', ['original' => $userMessage, 'extracted' => $extracted]);
                return $extracted;
            }
        } catch (\Throwable $e) {
            Log::warning('QueryExtractor failed, using original: ' . $e->getMessage());
        }

        return $userMessage;
    }
}
