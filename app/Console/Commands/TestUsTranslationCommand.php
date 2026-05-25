<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use GuzzleHttp\Client as GuzzleClient;

/**
 * Test: translate one US Supreme Court case to Georgian via Gemini.
 *
 * Usage:
 *   php artisan us:test-translate
 *   php artisan us:test-translate --id=12345
 */
class TestUsTranslationCommand extends Command
{
    protected $signature = 'us:test-translate {--id= : Specific external_id to translate}';
    protected $description = 'Test Georgian translation of one US Supreme Court case via Gemini';

    private const CHUNK_SIZE = 3000;

    public function handle(): int
    {
        $id = $this->option('id');

        $case = $id
            ? DB::connection('pgvector')->table('us_cases')->where('external_id', $id)->first()
            : DB::connection('pgvector')->table('us_cases')->whereNotNull('content')->inRandomOrder()->first();

        if (! $case) {
            $this->error('No case found.');
            return 1;
        }

        $this->info("Case ID:   {$case->external_id}");
        $this->info("Name:      {$case->name_abbreviation}");
        $this->info("Citation:  {$case->citation}");
        $this->info("Court:     {$case->court_name}");
        $this->info("Date:      {$case->decision_date}");
        $this->info("Content:   " . mb_strlen($case->content) . " chars");
        $this->newLine();

        $chunks = $this->chunk($case->content);
        $this->info("Chunks: " . count($chunks));
        $this->newLine();

        $translatedChunks = [];

        foreach ($chunks as $i => $chunk) {
            $this->line("Translating chunk " . ($i + 1) . "/" . count($chunks) . " (" . mb_strlen($chunk) . " chars)...");

            $translated = $this->translate($chunk);

            if ($translated === null) {
                $this->error("Translation failed on chunk " . ($i + 1));
                return 1;
            }

            $translatedChunks[] = $translated;
            $this->line("  → " . mb_substr($translated, 0, 150) . "...");
            $this->newLine();

            if ($i < count($chunks) - 1) {
                sleep(1);
            }
        }

        $fullTranslation = implode("\n\n", $translatedChunks);

        $this->info("=== სრული თარგმანი (" . mb_strlen($fullTranslation) . " სიმბოლო) ===");
        $this->line(mb_substr($fullTranslation, 0, 1000));

        return 0;
    }

    private function chunk(string $text): array
    {
        $chunks  = [];
        $len     = mb_strlen($text);
        $offset  = 0;
        $overlap = 200;

        while ($offset < $len) {
            $chunks[] = mb_substr($text, $offset, self::CHUNK_SIZE);
            $offset  += self::CHUNK_SIZE - $overlap;
        }

        return $chunks;
    }

    private function translate(string $englishText): ?string
    {
        $apiKey  = config('ai.gemini.api_key');
        $model   = config('ai.gemini.chat_model', 'gemini-2.5-flash');
        $baseUrl = config('ai.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');

        $prompt = <<<PROMPT
შენი ამოცანა: ამერიკული სასამართლო ტექსტის ზუსტი თარგმანი ქართულად.

წესები:
- დაწერე ᲛᲮᲝᲚᲝᲓ ქართული თარგმანი
- არანაირი ინგლისური სიტყვა ან განმარტება
- არანაირი კომენტარი, შენიშვნა ან ახსნა
- იურიდიული ტერმინები ქართულ სამართლებრივ ტერმინოლოგიაზე გადაიყვანე
- თუ ტერმინი სპეციფიკურია, ტრანსლიტერაციით ჩაწერე ქართულად

ინგლისური ტექსტი:
{$englishText}
PROMPT;

        try {
            $client   = new GuzzleClient(['timeout' => 60]);
            $response = $client->post("{$baseUrl}/models/{$model}:generateContent?key={$apiKey}", [
                'json' => [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]]
                    ],
                    'generationConfig' => [
                        'temperature'     => 0.1,
                        'maxOutputTokens' => 4096,
                    ],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

        } catch (\Throwable $e) {
            $this->error("Gemini error: " . $e->getMessage());
            return null;
        }
    }
}
