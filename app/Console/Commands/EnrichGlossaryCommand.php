<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Enriches glossary_raw.json with GPT-generated definitions.
 *
 * Input:  storage/app/glossary_raw.json  (from glossary:extract)
 * Output: storage/app/glossary_enriched.json
 *
 * Each term gets:
 *   ka_definition — Georgian legal definition (1-2 sentences)
 *   en_note       — English equivalent + what it is NOT (to avoid mistranslation)
 *   domain        — legal domain
 *   synonyms_ka   — common synonym forms
 *
 * Usage:
 *   php artisan glossary:enrich                  # enrich all unreviewed
 *   php artisan glossary:enrich --top=600        # only top 600 by frequency
 *   php artisan glossary:enrich --batch=20       # terms per GPT call
 *   php artisan glossary:enrich --re-enrich      # redo already enriched
 */
class EnrichGlossaryCommand extends Command
{
    protected $signature = 'glossary:enrich
                            {--top=1000     : How many top-frequency terms to enrich}
                            {--batch=20     : Terms per GPT call}
                            {--re-enrich    : Re-enrich already enriched terms}';

    protected $description = 'Enrich glossary_raw.json with GPT-4.1-mini definitions → glossary_enriched.json';

    private string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct()
    {
        parent::__construct();
        $this->apiKey  = config('openai.api_key');
        $this->model   = config('openai.extraction_model', 'gpt-4.1-mini');
        $this->baseUrl = config('openai.base_url', 'https://api.openai.com/v1');
    }

    public function handle(): int
    {
        $rawPath      = storage_path('app/glossary_raw.json');
        $enrichedPath = storage_path('app/glossary_enriched.json');

        if (!file_exists($rawPath)) {
            $this->error("glossary_raw.json not found. Run: php artisan glossary:extract first.");
            return self::FAILURE;
        }

        $terms     = json_decode(file_get_contents($rawPath), true);
        $top       = (int) $this->option('top');
        $batchSize = (int) $this->option('batch');
        $reEnrich  = $this->option('re-enrich');

        // Load existing enriched file if exists (to resume)
        $enriched = [];
        if (file_exists($enrichedPath)) {
            $enriched = json_decode(file_get_contents($enrichedPath), true) ?? [];
        }
        $enrichedByTerm = [];
        foreach ($enriched as $e) {
            $enrichedByTerm[$e['term']] = $e;
        }

        // Take top N by frequency
        $toProcess = array_slice($terms, 0, $top);

        // Filter: skip already enriched unless --re-enrich
        if (!$reEnrich) {
            $toProcess = array_filter(
                $toProcess,
                fn($t) => !isset($enrichedByTerm[$t['term']]) || empty($t['ka_definition'])
            );
        }

        $toProcess = array_values($toProcess);
        $total     = count($toProcess);

        $this->info("Terms to enrich: {$total} (batch size: {$batchSize})");

        if ($total === 0) {
            $this->info('Nothing to do. Use --re-enrich to redo existing entries.');
            return self::SUCCESS;
        }

        $bar      = $this->output->createProgressBar($total);
        $enriched = count($enrichedByTerm);
        $failed   = 0;

        foreach (array_chunk($toProcess, $batchSize) as $batch) {
            $results = $this->enrichBatch($batch);

            foreach ($batch as $term) {
                $result = $results[$term['term']] ?? null;

                if ($result) {
                    $enrichedByTerm[$term['term']] = array_merge($term, $result, ['reviewed' => false]);
                    $enriched++;
                } else {
                    // Keep original with empty fields
                    $enrichedByTerm[$term['term']] = $term;
                    $failed++;
                }

                $bar->advance();
            }

            // Save after every batch (resume-safe)
            $this->saveEnriched($enrichedPath, $enrichedByTerm);
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done. Enriched: {$enriched}, Failed/skipped: {$failed}");
        $this->info("Saved to: {$enrichedPath}");
        $this->info("Next step: php artisan glossary:enrich --top=10000 for full coverage");
        $this->info("Or open " . storage_path('app/glossary_enriched.json') . " and review top 600.");

        return self::SUCCESS;
    }

    private function enrichBatch(array $batch): array
    {
        $termList = implode("\n", array_map(
            fn($t, $i) => ($i + 1) . ". {$t['term']} (count: {$t['count']})",
            $batch,
            array_keys($batch)
        ));

        $systemPrompt = <<<PROMPT
You are a Georgian legal terminology expert.
For each Georgian legal term provided, output a JSON array with objects containing:
- "term": the exact term as given
- "ka_definition": 1-2 sentence definition in Georgian (simple, precise)
- "en_note": English equivalent(s) AND what it is NOT (e.g. "court order. NOT: verdict (განაჩენი)")
- "domain": one of: civil, criminal, admin, corporate, labor, property, tax, family, procedure, echr, general
- "synonyms_ka": array of common Georgian synonym forms (inflected variants or near-synonyms)

Rules:
- Only output valid JSON array, nothing else
- If a term is not a legal term (e.g. common word), set domain="general" and note it
- Focus on Georgian law specifically, not generic translations
PROMPT;

        $userPrompt = "Enrich these Georgian legal terms:\n\n{$termList}\n\nOutput JSON array:";

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout(60)
                ->post("{$this->baseUrl}/chat/completions", [
                    'model'       => $this->model,
                    'temperature' => 0,
                    'max_tokens'  => count($batch) * 120,
                    'messages'    => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user',   'content' => $userPrompt],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('EnrichGlossary: API error', ['status' => $response->status()]);
                return [];
            }

            $content = trim($response->json('choices.0.message.content') ?? '');

            // Extract JSON array from response
            if (preg_match('/\[.*\]/su', $content, $m)) {
                $parsed = json_decode($m[0], true);
                if (is_array($parsed)) {
                    $result = [];
                    foreach ($parsed as $item) {
                        if (!empty($item['term'])) {
                            $result[$item['term']] = [
                                'ka_definition' => $item['ka_definition'] ?? null,
                                'en_note'       => $item['en_note']       ?? null,
                                'domain'        => $item['domain']        ?? 'general',
                                'synonyms_ka'   => $item['synonyms_ka']   ?? [],
                            ];
                        }
                    }
                    return $result;
                }
            }

            Log::warning('EnrichGlossary: could not parse response', ['content' => mb_substr($content, 0, 200)]);
            return [];

        } catch (\Throwable $e) {
            Log::warning('EnrichGlossary: exception — ' . $e->getMessage());
            return [];
        }
    }

    private function saveEnriched(string $path, array $byTerm): void
    {
        // Sort by count desc
        $sorted = array_values($byTerm);
        usort($sorted, fn($a, $b) => ($b['count'] ?? 0) <=> ($a['count'] ?? 0));
        file_put_contents($path, json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}
