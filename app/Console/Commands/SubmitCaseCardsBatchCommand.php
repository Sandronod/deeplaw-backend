<?php

namespace App\Console\Commands;

use App\Services\AI\CaseCardExtractorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Submits all pending court cases to OpenAI Batch API for case card extraction.
 * 50% cheaper than real-time API. Results ready within 1-24 hours.
 *
 * Usage:
 *   php artisan cases:submit-batch             # all pending
 *   php artisan cases:submit-batch --fresh     # re-submit all (overwrite existing)
 *   php artisan cases:submit-batch --from=5000 # resume from case id
 */
class SubmitCaseCardsBatchCommand extends Command
{
    protected $signature = 'cases:submit-batch
                            {--fresh  : Re-submit even if case_card already exists}
                            {--from=0 : Start from this court_cases.id}';

    protected $description = 'Submit court cases to OpenAI Batch API for case card extraction (~50% cheaper)';

    private const SYSTEM_PROMPT = <<<'PROMPT'
შენ ხარ სამართლებრივი ექსტრაქტორი. გადაწყვეტილების ტექსტიდან ამოიყვანე JSON და მხოლოდ JSON დააბრუნე.

JSON სქემა (ყველა ველი სავალდებულოა):
{
  "legal_issue": "სამართლებრივი საკითხი — 1-2 წინადადება ქართულად",
  "applied_articles": ["სკ 128", "სსსკ 394"],
  "holding": "სასამართლოს დასკვნა — 1 წინადადება ქართულად",
  "outcome": "upheld|dismissed|partial|remanded|unclear"
}

წესები:
- legal_issue: ამოიყვანე ძირითადი სამართლებრივი კითხვა
- applied_articles: მხოლოდ პირდაპირ დასახელებული მუხლები. თუ არ არის — ცარიელი მასივი []
- holding: სასამართლოს საბოლოო დასკვნა
- outcome: upheld=დაკმაყოფ., dismissed=უარყოფილი, partial=ნაწილობრ., remanded=დაბრუნდა, unclear=გაუგებარია
- დააბრუნე მხოლოდ JSON — არა markdown, არა კომენტარი
PROMPT;

    public function handle(): int
    {
        ini_set('max_execution_time', 0);

        $fresh  = $this->option('fresh');
        $fromId = (int) $this->option('from');
        $apiKey = config('openai.api_key');
        $baseUrl = config('openai.base_url', 'https://api.openai.com/v1');

        $query = DB::connection('pgvector')
            ->table('court_cases')
            ->where('id', '>=', $fromId)
            ->when(!$fresh, fn($q) => $q->whereNull('case_card'))
            ->orderBy('id');

        $total = $query->count();

        if ($total === 0) {
            $this->info('Nothing to process — all cases already have cards.');
            return self::SUCCESS;
        }

        $this->info("Preparing batch for {$total} cases...");

        // ── Build JSONL file ──────────────────────────────────────────────────
        $tmpFile = tempnam(sys_get_temp_dir(), 'case_batch_') . '.jsonl';
        $handle  = fopen($tmpFile, 'w');
        $count   = 0;

        $query->chunk(500, function ($cases) use ($handle, &$count) {
            foreach ($cases as $case) {
                $chunks = DB::connection('pgvector')
                    ->table('court_chunks')
                    ->where('case_id', $case->id)
                    ->orderBy('chunk_index')
                    ->pluck('content')
                    ->toArray();

                if (empty($chunks)) {
                    continue;
                }

                $fullText = implode("\n\n", $chunks);
                $first    = mb_substr($fullText, 0, 500);
                $last     = mb_substr($fullText, -2000);
                $text     = $first . "\n\n[...]\n\n" . $last;

                $line = json_encode([
                    'custom_id' => 'case-' . $case->id,
                    'method'    => 'POST',
                    'url'       => '/v1/chat/completions',
                    'body'      => [
                        'model'       => 'gpt-4.1-mini',
                        'messages'    => [
                            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                            ['role' => 'user',   'content' => $text],
                        ],
                        'max_tokens'  => 300,
                        'temperature' => 0.0,
                    ],
                ], JSON_UNESCAPED_UNICODE);

                fwrite($handle, $line . "\n");
                $count++;
            }
        });

        fclose($handle);

        $this->info("JSONL ready: {$count} requests. Uploading...");

        // ── Upload file to OpenAI ─────────────────────────────────────────────
        $uploadResponse = Http::withToken($apiKey)
            ->timeout(120)
            ->attach('file', file_get_contents($tmpFile), 'batch.jsonl', ['Content-Type' => 'application/jsonl'])
            ->post("{$baseUrl}/files", ['purpose' => 'batch']);

        unlink($tmpFile);

        if ($uploadResponse->failed()) {
            $this->error('File upload failed: ' . $uploadResponse->body());
            return self::FAILURE;
        }

        $fileId = $uploadResponse->json('id');
        $this->info("File uploaded: {$fileId}");

        // ── Create batch ──────────────────────────────────────────────────────
        $batchResponse = Http::withToken($apiKey)
            ->timeout(30)
            ->post("{$baseUrl}/batches", [
                'input_file_id'      => $fileId,
                'endpoint'           => '/v1/chat/completions',
                'completion_window'  => '24h',
            ]);

        if ($batchResponse->failed()) {
            $this->error('Batch creation failed: ' . $batchResponse->body());
            return self::FAILURE;
        }

        $batchId = $batchResponse->json('id');

        // ── Save to DB ────────────────────────────────────────────────────────
        DB::connection('pgvector')->table('batch_jobs')->insert([
            'batch_id'   => $batchId,
            'type'       => 'case_cards',
            'status'     => 'submitted',
            'input_file' => $fileId,
            'total'      => $count,
        ]);

        $this->info("✅ Batch submitted!");
        $this->table(
            ['Batch ID', 'Cases', 'Est. Cost', 'Est. Time'],
            [[$batchId, $count, '~$' . round($count * 0.00025, 1), '1-24 hours']]
        );
        $this->info("Check status: php artisan cases:download-batch");

        return self::SUCCESS;
    }
}
