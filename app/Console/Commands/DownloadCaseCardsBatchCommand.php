<?php

namespace App\Console\Commands;

use App\Services\AI\CaseCardExtractorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Checks OpenAI Batch status and downloads results when complete.
 *
 * Usage:
 *   php artisan cases:download-batch           # latest submitted batch
 *   php artisan cases:download-batch --id=xxx  # specific batch
 */
class DownloadCaseCardsBatchCommand extends Command
{
    protected $signature = 'cases:download-batch
                            {--id= : Specific batch ID (default: latest)}';

    protected $description = 'Download completed OpenAI Batch results and save case cards to DB';

    public function handle(CaseCardExtractorService $extractor): int
    {
        $apiKey  = config('openai.api_key');
        $baseUrl = config('openai.base_url', 'https://api.openai.com/v1');

        // ── Resolve batch ─────────────────────────────────────────────────────
        $batchId = $this->option('id');

        if (!$batchId) {
            $job = DB::connection('pgvector')
                ->table('batch_jobs')
                ->where('type', 'case_cards')
                ->whereIn('status', ['submitted', 'in_progress'])
                ->orderByDesc('submitted_at')
                ->first();

            if (!$job) {
                $this->error('No active batch found. Run cases:submit-batch first.');
                return self::FAILURE;
            }

            $batchId = $job->batch_id;
        }

        $this->info("Checking batch: {$batchId}");

        // ── Check status ──────────────────────────────────────────────────────
        $statusResponse = Http::withToken($apiKey)
            ->timeout(30)
            ->get("{$baseUrl}/batches/{$batchId}");

        if ($statusResponse->failed()) {
            $this->error('Status check failed: ' . $statusResponse->body());
            return self::FAILURE;
        }

        $batch  = $statusResponse->json();
        $status = $batch['status'];

        $this->info("Status: {$status}");

        if (in_array($status, ['validating', 'in_progress', 'finalizing'])) {
            $completed = $batch['request_counts']['completed'] ?? 0;
            $total     = $batch['request_counts']['total']     ?? 0;
            $this->info("Progress: {$completed}/{$total}");
            $this->warn('Not ready yet. Re-run this command later.');

            DB::connection('pgvector')
                ->table('batch_jobs')
                ->where('batch_id', $batchId)
                ->update(['status' => 'in_progress']);

            return self::SUCCESS;
        }

        if ($status !== 'completed') {
            $this->error("Batch failed with status: {$status}");
            DB::connection('pgvector')
                ->table('batch_jobs')
                ->where('batch_id', $batchId)
                ->update(['status' => $status]);
            return self::FAILURE;
        }

        // ── Download results ──────────────────────────────────────────────────
        $outputFileId = $batch['output_file_id'];
        $this->info("Downloading results file: {$outputFileId}");

        $fileResponse = Http::withToken($apiKey)
            ->timeout(300)
            ->get("{$baseUrl}/files/{$outputFileId}/content");

        if ($fileResponse->failed()) {
            $this->error('Download failed: ' . $fileResponse->body());
            return self::FAILURE;
        }

        $lines = explode("\n", trim($fileResponse->body()));
        $this->info("Processing " . count($lines) . " results...");

        $succeeded = 0;
        $failed    = 0;
        $bar       = $this->output->createProgressBar(count($lines));
        $bar->start();

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $result = json_decode($line, true);
            if (!$result) {
                $failed++;
                $bar->advance();
                continue;
            }

            // Extract case_id from custom_id "case-123"
            $caseId = (int) str_replace('case-', '', $result['custom_id'] ?? '');
            if (!$caseId) {
                $failed++;
                $bar->advance();
                continue;
            }

            // Check for API-level error
            if (!empty($result['error'])) {
                $failed++;
                $bar->advance();
                continue;
            }

            $raw = trim($result['response']['body']['choices'][0]['message']['content'] ?? '');

            // Strip markdown fences
            $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw);
            $raw = preg_replace('/\s*```$/i', '', $raw);

            $card = json_decode($raw, true);

            if (!is_array($card) || empty($card['legal_issue'])) {
                $failed++;
                $bar->advance();
                continue;
            }

            // Fetch full text for regex article scanning
            $fullText = DB::connection('pgvector')
                ->table('court_chunks')
                ->where('case_id', $caseId)
                ->orderBy('chunk_index')
                ->pluck('content')
                ->implode("\n\n");

            $card = $this->normalise($card, $fullText);

            DB::connection('pgvector')
                ->table('court_cases')
                ->where('id', $caseId)
                ->update(['case_card' => json_encode($card, JSON_UNESCAPED_UNICODE)]);

            $succeeded++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        // ── Update batch record ───────────────────────────────────────────────
        DB::connection('pgvector')
            ->table('batch_jobs')
            ->where('batch_id', $batchId)
            ->update([
                'status'       => 'completed',
                'output_file'  => $outputFileId,
                'succeeded'    => $succeeded,
                'failed'       => $failed,
                'completed_at' => now(),
            ]);

        $this->table(
            ['Succeeded', 'Failed'],
            [[$succeeded, $failed]]
        );

        $this->info("✅ Done. Run 'php artisan cases:embed-cards' next.");

        return self::SUCCESS;
    }

    private function normalise(array $card, string $fullText = ''): array
    {
        $validOutcomes = ['upheld', 'dismissed', 'partial', 'remanded', 'unclear'];

        $gptArticles   = array_values(array_filter(
            (array) ($card['applied_articles'] ?? []),
            fn($a) => is_string($a) && mb_strlen(trim($a)) > 0
        ));
        $regexArticles = !empty($fullText) ? $this->extractArticlesRegex($fullText) : [];
        $articles      = array_values(array_unique(array_merge($gptArticles, $regexArticles)));

        return [
            'legal_issue'      => mb_substr((string) ($card['legal_issue'] ?? ''), 0, 500),
            'applied_articles' => $articles,
            'holding'          => mb_substr((string) ($card['holding'] ?? ''), 0, 500),
            'outcome'          => in_array($card['outcome'] ?? '', $validOutcomes, true)
                ? $card['outcome']
                : 'unclear',
        ];
    }

    private function extractArticlesRegex(string $text): array
    {
        $found = [];

        $patterns = [
            '/\b(სკ|სსკ|სსსკ|ზაკ|შრ\.?კ\.?|ადმ\.?კ\.?)[-\s–]*(\d+)/u',
            '/\b(\d{1,4})-?(?:ე|ელ|ლ)?\s+მუხლ/u',
            '/მუხლ[იით]+\s+(\d{1,4})/u',
        ];

        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);
            foreach ($matches as $m) {
                if (isset($m[2])) {
                    $code    = mb_strtoupper(preg_replace('/[^a-zA-Zა-ჰ]/u', '', $m[1]));
                    $found[] = "{$code} {$m[2]}";
                } else {
                    $found[] = 'მუხლი ' . $m[1];
                }
            }
        }

        return array_slice(array_values(array_unique($found)), 0, 10);
    }
}
