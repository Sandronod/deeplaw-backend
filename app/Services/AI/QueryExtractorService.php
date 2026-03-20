<?php

namespace App\Services\AI;

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
        $this->model   = config('openai.chat_model', 'gpt-4.1');
        $this->baseUrl = config('openai.base_url', 'https://api.openai.com/v1');
    }

    /**
     * ამოიღებს სამართლებრივ საძიებო ტერმინებს მომხმარებლის მესიჯიდან.
     *
     * "მიპოვე და შემიჯამე სახელმწიფო კომპენსაციის შესახებ გადაწყვეტილება"
     *   → "სახელმწიფო კომპენსაცია სახელმწიფო აკადემიური სტიპენდია"
     */
    public function extract(string $userMessage): string
    {
        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(15)
                ->post("{$this->baseUrl}/chat/completions", [
                    'model'       => $this->model,
                    'temperature' => 0,
                    'max_tokens'  => 120,
                    'messages'    => [
                        [
                            'role'    => 'system',
                            'content' => <<<PROMPT
შენი ამოცანაა მომხმარებლის მოთხოვნიდან ამოიღო მხოლოდ სამართლებრივი საძიებო ტერმინები/თემები.

წესები:
- ამოიღე task instruction სიტყვები ("მიპოვე", "შემიჯამე", "განმარტე", "find", "summarize" და მსგავსი)
- ამოიღე ზოგადი სიტყვები: "გადაწყვეტილება", "სასამართლო", "დავა"
- დატოვე მხოლოდ კონკრეტული სამართლებრივი თემა/საგანი/ტერმინი
- დააბრუნე მხოლოდ ამოღებული ტერმინები, სხვა არაფერი
- თუ კონკრეტული case number ან თარიღი არის, ისიც დატოვე
- სახელები (მოსამართლეების, მხარეების, ადვოკატების, კომპანიების) — ᲡᲐᲕᲐᲚᲓᲔᲑᲣᲚᲝᲓ შეინახე, არ ამოიღო
- "მოსამართლე X" → შეინახე "X" (მხოლოდ სახელი)
PROMPT,
                        ],
                        [
                            'role'    => 'user',
                            'content' => $userMessage,
                        ],
                    ],
                ]);

            if ($response->successful()) {
                $extracted = trim($response->json('choices.0.message.content') ?? '');
                if (!empty($extracted)) {
                    Log::debug('QueryExtractor', [
                        'original'  => $userMessage,
                        'extracted' => $extracted,
                    ]);
                    return $extracted;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('QueryExtractor failed, using original query: ' . $e->getMessage());
        }

        // Fallback — original query გამოიყენება
        return $userMessage;
    }
}
