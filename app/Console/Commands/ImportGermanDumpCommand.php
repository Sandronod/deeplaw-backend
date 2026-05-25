<?php

namespace App\Console\Commands;

use Flow\Parquet\ParquetFile\RowGroupBuilder\PageSizeCalculator;
use Flow\Parquet\Reader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Imports German court cases from Parquet dump files into german_cases table.
 * Resumable — skips already-imported external_ids.
 *
 * Usage:
 *   php artisan german:import-dump
 *   php artisan german:import-dump --path=public/german
 */
class ImportGermanDumpCommand extends Command
{
    protected $signature = 'german:import-dump
        {--path=public/german : Folder containing Parquet files}
        {--batch=500          : DB insert batch size}';

    protected $description = 'Import German court cases from local Parquet dump into german_cases';

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
                $reader = new Reader();
                $parquet = $reader->read($file);

                $batch   = [];
                $inserted = 0;
                $skipped  = 0;

                foreach ($parquet->values() as $row) {
                    $externalId = (int) ($row['id'] ?? 0);

                    if (! $externalId) {
                        continue;
                    }

                    // Skip already imported
                    $exists = DB::connection('pgvector')
                        ->table('german_cases')
                        ->where('external_id', $externalId)
                        ->exists();

                    if ($exists) {
                        $skipped++;
                        continue;
                    }

                    $court = $row['court'] ?? [];
                    if (is_string($court)) {
                        $court = json_decode($court, true) ?? [];
                    }

                    $content = isset($row['content']) ? $this->stripHtml((string) $row['content']) : null;

                    $batch[] = [
                        'external_id'     => $externalId,
                        'slug'            => isset($row['slug']) ? mb_substr((string) $row['slug'], 0, 300) : null,
                        'file_number'     => isset($row['file_number']) ? mb_substr((string) $row['file_number'], 0, 200) : null,
                        'date'            => $this->parseDate($row['date'] ?? null),
                        'decision_type'   => isset($row['type']) ? mb_substr((string) $row['type'], 0, 100) : null,
                        'ecli'            => isset($row['ecli']) ? mb_substr((string) $row['ecli'], 0, 200) : null,
                        'court_name'      => isset($court['name']) ? mb_substr((string) $court['name'], 0, 300) : null,
                        'court_slug'      => isset($court['slug']) ? mb_substr((string) $court['slug'], 0, 200) : null,
                        'jurisdiction'    => isset($court['jurisdiction']) ? mb_substr((string) $court['jurisdiction'], 0, 200) : null,
                        'level_of_appeal' => isset($court['level_of_appeal']) ? mb_substr((string) $court['level_of_appeal'], 0, 100) : null,
                        'content'         => $content,
                        'content_hash'    => $content ? md5($content) : null,
                        'created_at'      => now(),
                        'updated_at'      => now(),
                    ];

                    $inserted++;

                    if (count($batch) >= $batchSize) {
                        DB::connection('pgvector')->table('german_cases')->insert($batch);
                        $batch = [];
                        $this->line("  Inserted: {$inserted} | Skipped: {$skipped}");
                    }
                }

                // Insert remaining
                if (! empty($batch)) {
                    DB::connection('pgvector')->table('german_cases')->insert($batch);
                }

                $totalInserted += $inserted;
                $totalSkipped  += $skipped;

                $this->info("  Done: +{$inserted} inserted, {$skipped} skipped");

            } catch (\Throwable $e) {
                $this->error("  Failed on {$filename}: " . $e->getMessage());
            }
        }

        $total = DB::connection('pgvector')->table('german_cases')->count();
        $this->info("\nImport complete! Total in DB: {$total} | Inserted: {$totalInserted} | Skipped: {$totalSkipped}");

        return 0;
    }

    private function stripHtml(string $html): string
    {
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
        return null;
    }
}
