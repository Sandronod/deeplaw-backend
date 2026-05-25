<?php

namespace App\Console\Commands;

use Flow\Parquet\Reader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Imports US Supreme Court cases from Harvard CAP Parquet files.
 * Resumable — skips already-imported external_ids.
 *
 * Usage:
 *   php -d memory_limit=-1 artisan us:import-dump
 *   php -d memory_limit=-1 artisan us:import-dump --path=public/us_supreme
 */
class ImportUsCasesCommand extends Command
{
    protected $signature = 'us:import-dump
        {--path=public/us_supreme : Folder containing Parquet files}
        {--batch=500              : DB insert batch size}';

    protected $description = 'Import US Supreme Court cases from local Parquet dump into us_cases';

    public function handle(): int
    {
        $path      = base_path($this->option('path'));
        $batchSize = (int) $this->option('batch');

        if (! is_dir($path)) {
            $this->error("Directory not found: {$path}");
            return 1;
        }

        $files = glob($path . '/*.parquet');
        sort($files);

        if (empty($files)) {
            $this->error("No .parquet files found in {$path}");
            return 1;
        }

        $this->info("Found " . count($files) . " Parquet files.");

        $totalInserted = 0;
        $totalSkipped  = 0;

        foreach ($files as $file) {
            $filename = basename($file);
            $this->line("\nProcessing: {$filename}");

            try {
                $reader  = new Reader();
                $parquet = $reader->read($file);

                $batch    = [];
                $inserted = 0;
                $skipped  = 0;

                foreach ($parquet->values() as $row) {
                    $externalId = (string) ($row['id'] ?? '');

                    if ($externalId === '') {
                        continue;
                    }

                    $exists = DB::connection('pgvector')
                        ->table('us_cases')
                        ->where('external_id', $externalId)
                        ->exists();

                    if ($exists) {
                        $skipped++;
                        continue;
                    }

                    $content      = $this->extractContent($row);
                    $date         = $this->parseDate($row['decision_date'] ?? null);
                    $year         = $date ? (int) substr($date, 0, 4) : null;
                    $citation     = $this->firstCitation($row['citations'] ?? null);
                    $courtName    = $this->courtName($row['court'] ?? null);
                    $jurisdiction = $this->jurisdiction($row['jurisdiction'] ?? null);

                    $batch[] = [
                        'external_id'       => $externalId,
                        'name_abbreviation' => isset($row['name_abbreviation'])
                            ? mb_substr((string) $row['name_abbreviation'], 0, 500)
                            : null,
                        'name'              => isset($row['name'])
                            ? mb_substr((string) $row['name'], 0, 2000)
                            : null,
                        'citation'          => $citation,
                        'decision_date'     => $date,
                        'decision_year'     => $year,
                        'court_name'        => $courtName,
                        'jurisdiction'      => $jurisdiction,
                        'volume'            => isset($row['volume'])
                            ? mb_substr((string) $row['volume'], 0, 50)
                            : null,
                        'reporter'          => $this->reporter($row['reporter'] ?? null),
                        'content'           => $content,
                        'content_hash'      => $content ? md5($content) : null,
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ];

                    $inserted++;

                    if (count($batch) >= $batchSize) {
                        DB::connection('pgvector')->table('us_cases')->insert($batch);
                        $batch = [];
                        $this->line("  Inserted: {$inserted} | Skipped: {$skipped}");
                    }
                }

                if (! empty($batch)) {
                    DB::connection('pgvector')->table('us_cases')->insert($batch);
                }

                $totalInserted += $inserted;
                $totalSkipped  += $skipped;

                $this->info("  Done: +{$inserted} inserted, {$skipped} skipped");

            } catch (\Throwable $e) {
                $this->error("  Failed on {$filename}: " . $e->getMessage());
            }
        }

        $total = DB::connection('pgvector')->table('us_cases')->count();
        $this->info("\nImport complete! Total in DB: {$total} | Inserted: {$totalInserted} | Skipped: {$totalSkipped}");

        return 0;
    }

    // CAP stores opinions as JSON array: [{"type":"majority","text":"..."}]
    private function extractContent(array $row): ?string
    {
        $raw = $row['casebody'] ?? $row['opinions'] ?? $row['content'] ?? null;

        if ($raw === null) {
            return null;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $raw = $decoded;
            } else {
                // plain text already
                return $this->normalizeText($raw);
            }
        }

        // CAP casebody: {"data": {"opinions": [{"type":"...", "text":"..."}]}}
        if (isset($raw['data']['opinions'])) {
            $raw = $raw['data']['opinions'];
        } elseif (isset($raw['opinions'])) {
            $raw = $raw['opinions'];
        }

        if (is_array($raw)) {
            $parts = [];
            foreach ($raw as $opinion) {
                if (is_array($opinion) && isset($opinion['text'])) {
                    $parts[] = $opinion['text'];
                } elseif (is_string($opinion)) {
                    $parts[] = $opinion;
                }
            }
            return $this->normalizeText(implode("\n\n", $parts));
        }

        return null;
    }

    private function normalizeText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    private function parseDate(mixed $value): ?string
    {
        if (empty($value)) return null;
        $str = (string) $value;
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $str, $m)) {
            return $m[0];
        }
        // handle "1973-01" or "1973"
        if (preg_match('/^(\d{4})-(\d{2})$/', $str, $m)) {
            return "{$m[1]}-{$m[2]}-01";
        }
        if (preg_match('/^(\d{4})$/', $str, $m)) {
            return "{$m[1]}-01-01";
        }
        return null;
    }

    private function firstCitation(mixed $citations): ?string
    {
        if (empty($citations)) return null;

        if (is_string($citations)) {
            $citations = json_decode($citations, true) ?? [];
        }

        if (is_array($citations) && ! empty($citations)) {
            $first = $citations[0];
            if (is_array($first)) {
                return mb_substr((string) ($first['cite'] ?? $first['citation'] ?? ''), 0, 200) ?: null;
            }
            return mb_substr((string) $first, 0, 200);
        }

        return null;
    }

    private function courtName(mixed $court): ?string
    {
        if (empty($court)) return null;

        if (is_string($court)) {
            $court = json_decode($court, true) ?? $court;
        }

        if (is_array($court)) {
            return mb_substr((string) ($court['name'] ?? $court['name_abbreviation'] ?? ''), 0, 300) ?: null;
        }

        return mb_substr((string) $court, 0, 300);
    }

    private function jurisdiction(mixed $jurisdiction): ?string
    {
        if (empty($jurisdiction)) return null;

        if (is_string($jurisdiction)) {
            $decoded = json_decode($jurisdiction, true);
            if (is_array($decoded)) {
                return mb_substr((string) ($decoded['name_long'] ?? $decoded['name'] ?? ''), 0, 100) ?: null;
            }
            return mb_substr($jurisdiction, 0, 100);
        }

        if (is_array($jurisdiction)) {
            return mb_substr((string) ($jurisdiction['name_long'] ?? $jurisdiction['name'] ?? ''), 0, 100) ?: null;
        }

        return null;
    }

    private function reporter(mixed $reporter): ?string
    {
        if (empty($reporter)) return null;

        if (is_string($reporter)) {
            $decoded = json_decode($reporter, true);
            if (is_array($decoded)) {
                return mb_substr((string) ($decoded['full_name'] ?? $decoded['name'] ?? ''), 0, 100) ?: null;
            }
            return mb_substr($reporter, 0, 100);
        }

        if (is_array($reporter)) {
            return mb_substr((string) ($reporter['full_name'] ?? $reporter['name'] ?? ''), 0, 100) ?: null;
        }

        return null;
    }
}
