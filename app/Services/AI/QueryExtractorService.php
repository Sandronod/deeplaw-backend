<?php

namespace App\Services\AI;

use App\Services\Legal\LegalGlossaryService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QueryExtractorService
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct(private readonly LegalGlossaryService $glossary)
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
ამოიღე სამართლებრივი საძიებო ტერმინები შემდეგი წესებით:

ფორმატი:
- ერთი ტერმინი/ფრაზა თითო ხაზზე
- მაქსიმუმ 6 ხაზი
- მხოლოდ ტერმინები — კომენტარი, ახსნა, სრული წინადადებები დაუშვებელია

ამოიღე:
- task სიტყვები: "მიპოვე", "შემიჯამე", "განმარტე", "find", "summarize", "გთხოვ", "შეაფასე"
- ზოგადი სიტყვები: "გადაწყვეტილება", "სასამართლო", "დავა", "საქმე", "კითხვა"

შეინახე:
- სამართლის დარგი (მაგ: "სამოქალაქო სამართალი", "შრომის კანონი")
- ძირითადი სამართლებრივი ტერმინები (მაგ: "იძულებითი გამოყოფა", "კაპიტალის შეტანა")
- case number-ები, სახელები, ინსტიტუციები

მაგალითები:
"მიპოვე სადაზღვეო ხელშეკრულების შეწყვეტის შესახებ" → "სადაზღვეო ხელშეკრულება\nხელშეკრულების შეწყვეტა"
"შპს პარტნიორის კაპიტალის შეტანაზე უარი" → "შპს\nკაპიტალის შეტანა\nპარტნიორის გამოყოფა\nმეწარმეთა კანონი"
"ბავშვის მეურვეობა განქორწინებისას" → "მეურვეობა\nალიმენტი\nგანქორწინება\nოჯახის კოდექსი"
PROMPT,
                        ],
                        ['role' => 'user', 'content' => $userMessage],
                    ],
                ]);

            $extracted = trim($response->json('choices.0.message.content') ?? '');

            if (!empty($extracted)) {
                // Expand with glossary synonyms (e.g. სარჩელი → სასარჩელო განცხადება)
                $synonyms = $this->glossary->expandQuery($userMessage);
                if (!empty($synonyms)) {
                    $extracted .= "\n" . implode("\n", $synonyms);
                    Log::debug('QueryExtractor: glossary expansion', ['added' => $synonyms]);
                }

                Log::debug('QueryExtractor', ['original' => $userMessage, 'extracted' => $extracted]);
                return $extracted;
            }
        } catch (\Throwable $e) {
            Log::warning('QueryExtractor failed, using original: ' . $e->getMessage());
        }

        return $userMessage;
    }
}
