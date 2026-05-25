<?php

namespace App\Console\Commands;

use App\Services\AI\OllamaEmbeddingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TranslateGermanCasesCommand extends Command
{
    protected $signature = 'german:translate
        {--batch=10        : Cases to process per run}
        {--limit=1000      : Total cases to translate (0 = unlimited)}
        {--priority=1      : Filter by priority}
        {--chunk=800       : Chunk size in chars after translation}
        {--overlap=100     : Chunk overlap in chars}
        {--section=30000   : Max chars per Gemini API call}
        {--embed           : Also create bge-m3 embeddings after translation}
        {--model=          : Gemini model override}';

    protected $description = 'Translate full German cases to Georgian via Gemini, then chunk and embed';

    public function __construct(private readonly OllamaEmbeddingService $embedder)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $batchSize   = (int) $this->option('batch');
        $limit       = (int) $this->option('limit');
        $priority    = (int) $this->option('priority');
        $chunkSize   = (int) $this->option('chunk');
        $overlap     = (int) $this->option('overlap');
        $sectionSize = (int) $this->option('section');
        $embed       = $this->option('embed');
        $model       = $this->option('model') ?: config('services.gemini.model', 'gemini-2.0-flash');

        $query = DB::connection('pgvector')
            ->table('german_cases')
            ->whereNull('content_ka')
            ->where('priority', $priority)
            ->whereNotNull('content')
            ->where('content', '!=', '')
            ->select('id', 'external_id', 'content', 'court_name',
                     'level_of_appeal', 'decision_type', 'jurisdiction', 'date');

        $total = min($query->count(), $limit > 0 ? $limit : PHP_INT_MAX);

        if ($total === 0) {
            $this->info('No untranslated cases found.');
            return 0;
        }

        $this->info("Found {$total} cases (priority={$priority}). Model: {$model}");

        $bar       = $this->output->createProgressBar($total);
        $bar->start();
        $done      = 0;
        $failed    = 0;
        $processed = 0;

        $query->orderBy('id')->chunk($batchSize, function ($cases) use (
            &$done, &$failed, &$processed, $limit,
            $chunkSize, $overlap, $sectionSize, $embed, $model, $bar
        ) {
            foreach ($cases as $case) {
                if ($limit > 0 && $processed >= $limit) {
                    return false;
                }

                // 1. Translate full case → Georgian
                $georgianText = $this->translateFull($case->content, $sectionSize, $model);

                if (!$georgianText) {
                    $failed++;
                    $processed++;
                    $bar->advance();
                    $this->newLine();
                    $this->warn("Failed case id={$case->id}");
                    continue;
                }

                // 2. Save full Georgian translation to german_cases.content_ka
                DB::connection('pgvector')->table('german_cases')
                    ->where('id', $case->id)
                    ->update(['content_ka' => $georgianText]);

                // 3. Chunk the Georgian text
                $chunks = $this->chunkText($georgianText, $chunkSize, $overlap);

                // 4. Store each chunk with optional embedding
                foreach ($chunks as $i => $chunkText) {
                    $embedding = null;
                    if ($embed) {
                        try {
                            $vec = $this->embedder->embed($chunkText);
                            $embedding = '[' . implode(',', $vec) . ']';
                        } catch (\Throwable $e) {
                            Log::warning('german:translate embed failed', ['msg' => $e->getMessage()]);
                        }
                    }

                    DB::connection('pgvector')->table('german_chunks')->insert([
                        'case_id'         => $case->id,
                        'external_id'     => $case->external_id,
                        'court_name'      => $case->court_name,
                        'level_of_appeal' => $case->level_of_appeal,
                        'decision_type'   => $case->decision_type,
                        'jurisdiction'    => $case->jurisdiction,
                        'date_year'       => $case->date ? (int) substr($case->date, 0, 4) : null,
                        'chunk_index'     => $i,
                        'content'         => $chunkText,
                        'embedding'       => $embedding,
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ]);
                }

                $done++;
                $processed++;
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Done. Translated: {$done} | Failed: {$failed}");

        return 0;
    }

    /**
     * Translate full case text to Georgian.
     * If text is longer than $sectionSize, splits into sections, translates each, then joins.
     */
    private function translateFull(string $text, int $sectionSize, string $model): ?string
    {
        $len = mb_strlen($text);

        if ($len <= $sectionSize) {
            return $this->callGemini($text, $model);
        }

        // Split into sections at paragraph boundaries
        $sections  = $this->splitIntoSections($text, $sectionSize);
        $translated = [];

        foreach ($sections as $section) {
            $result = $this->callGemini($section, $model);
            if (!$result) {
                return null;
            }
            $translated[] = $result;
        }

        return implode("\n\n", $translated);
    }

    /**
     * Split text into sections of max $size chars, breaking at paragraph boundaries (\n\n).
     */
    private function splitIntoSections(string $text, int $size): array
    {
        $paragraphs = preg_split('/\n{2,}/', $text);
        $sections   = [];
        $current    = '';

        foreach ($paragraphs as $para) {
            if (mb_strlen($current) + mb_strlen($para) + 2 > $size && $current !== '') {
                $sections[] = trim($current);
                $current    = $para;
            } else {
                $current .= ($current ? "\n\n" : '') . $para;
            }
        }

        if (trim($current) !== '') {
            $sections[] = trim($current);
        }

        return $sections ?: [$text];
    }

    private const SYSTEM_PROMPT = <<<'PROMPT'
შენ ხარ მაღალი კვალიფიკაციის იურიდიული მთარგმნელი, რომელიც სპეციალიზებულია გერმანულ და ქართულ სამართლებრივ სისტემებზე. შენი ამოცანაა გერმანული სასამართლო გადაწყვეტილებების აკურატული თარგმნა ქართულ ენაზე.

**თარგმნის წესები:**
- არასოდეს შეამოკლო ან გამოტოვო წინადადება — თარგმანი უნდა იყოს სრული და მოიცავდეს ორიგინალის ყველა იურიდიულ ნიუანსს
- გამოიყენე ოფიციალური ქართული იურიდიული ტერმინოლოგია (მაგ. "rechtskräftiges Urteil" = "კანონიერ ძალაში შესული გადაწყვეტილება", "Schiedsspruch" = "საარბიტრაჟო გადაწყვეტილება", "Berufung" = "სააპელაციო საჩივარი")
- შეინარჩუნე წინადადების ლოგიკური ჯაჭვი და სამართლებრივი სტრუქტურა
- საქმის ნომრები, სტატიების მითითებები და თარიღები უცვლელად დატოვე
- პასუხი დააბრუნე მხოლოდ თარგმნილი ტექსტის სახით, ყოველგვარი შესავალი ფრაზების გარეშე

**მაგალითი:**
Input: "Der Schiedsspruch ist weiter gemäß Art. 54 ICSID-Konvention in jedem Vertragsstaat bindend und wie ein rechtskräftiges Urteil eines innerstaatlichen Gerichts zu behandeln."
Output: "საარბიტრაჟო გადაწყვეტილება, ICSID-ის კონვენციის 54-ე მუხლის შესაბამისად, სავალდებულოა ყველა ხელშემკვრელი სახელმწიფოსთვის და მას ისე უნდა მოეპყრონ, როგორც შიდასახელმწიფოებრივი სასამართლოს კანონიერ ძალაში შესულ გადაწყვეტილებას."
PROMPT;

    private function callGemini(string $text, string $model): ?string
    {
        $apiKey  = config('services.gemini.key');
        $baseUrl = config('services.gemini.base_url', 'https://generativelanguage.googleapis.com/v1beta');
        $timeout = 180;

        try {
            $response = Http::timeout($timeout)
                ->post("{$baseUrl}/models/{$model}:generateContent?key={$apiKey}", [
                    'system_instruction' => [
                        'parts' => [['text' => self::SYSTEM_PROMPT]],
                    ],
                    'contents' => [[
                        'parts' => [[
                            'text' => $text,
                        ]],
                    ]],
                    'generationConfig' => [
                        'temperature'     => 0,
                        'maxOutputTokens' => 65536,
                        'thinkingConfig'  => ['thinkingBudget' => 0],
                    ],
                ]);

            if ($response->failed()) {
                $err = $response->json('error.message') ?? $response->body();
                Log::error('Gemini translate error', ['status' => $response->status(), 'error' => $err]);
                $this->newLine();
                $this->warn("Gemini error {$response->status()}: " . mb_substr($err, 0, 200));
                return null;
            }

            return $response->json('candidates.0.content.parts.0.text') ?? null;

        } catch (\Throwable $e) {
            Log::error('Gemini translate exception', ['msg' => $e->getMessage()]);
            $this->newLine();
            $this->warn("Gemini exception: " . $e->getMessage());
            return null;
        }
    }

    private function chunkText(string $text, int $size, int $overlap): array
    {
        $chunks = [];
        $len    = mb_strlen($text);
        $start  = 0;

        while ($start < $len) {
            $chunk = mb_substr($text, $start, $size);
            if (mb_strlen(trim($chunk)) > 50) {
                $chunks[] = trim($chunk);
            }
            $start += $size - $overlap;
        }

        return $chunks ?: [mb_substr($text, 0, $size)];
    }
}
