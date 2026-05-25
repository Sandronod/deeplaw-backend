<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches all German court cases from Open Legal Data API.
 * Stores metadata + full German text in german_cases.
 * Resumable — saves progress to file, skips already-fetched cases.
 *
 * Usage:
 *   php artisan german:fetch
 *   php artisan german:fetch --delay=1 --page-size=100
 */
class FetchGermanCasesCommand extends Command
{
    protected $signature = 'german:fetch
        {--delay=1        : Seconds between requests}
        {--page-size=100  : Cases per API page (max 100)}
        {--start-page=1   : Resume from this page}';

    protected $description = 'Fetch all German court cases from Open Legal Data API (resumable)';

    private const API_BASE      = 'https://de.openlegaldata.io/api';
    private const TIMEOUT       = 30;
    private const PROGRESS_FILE = 'storage/german_fetch_progress.json';

    public function handle(): int
    {
        $delay    = max(0, (int) $this->option('delay'));
        $pageSize = min(100, max(10, (int) $this->option('page-size')));

        $explicit  = (int) $this->option('start-page');
        $startPage = $explicit > 1 ? $explicit : $this->loadProgress();

        $this->info("Fetching German cases from Open Legal Data...");
        $this->info("Page size: {$pageSize} | Delay: {$delay}s | Start page: {$startPage}");

        $page      = $startPage;
        $inserted  = 0;
        $skipped   = 0;
        $totalPages = null;

        // offset-based pagination: skip already-fetched cases
        $offset  = ($page - 1) * $pageSize;
        $nextUrl = self::API_BASE . '/cases/?' . http_build_query([
            'limit'  => $pageSize,
            'offset' => $offset,
            'format' => 'json',
        ]);

        while ($nextUrl) {
            $this->line("  Page {$page}" . ($totalPages ? "/{$totalPages}" : '') . " | inserted: {$inserted} skipped: {$skipped}");

            try {
                $response = Http::timeout(self::TIMEOUT)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (compatible; LegalResearch/1.0)',
                        'Accept'     => 'application/json',
                    ])
                    ->get($nextUrl);
            } catch (\Exception $e) {
                $this->warn("  Request failed: {$e->getMessage()} — retrying in 10s");
                sleep(10);
                continue;
            }

            if ($response->status() === 429) {
                $this->warn("  Rate limited (429) — waiting 30s");
                sleep(30);
                continue;
            }

            if ($response->status() === 404) {
                $this->info("  404 on page {$page} — all pages done.");
                break;
            }

            if ($response->failed()) {
                $this->warn("  HTTP {$response->status()} — retrying in 5s");
                sleep(5);
                continue;
            }

            $json = $response->json();

            if (empty($json['results'])) {
                $this->info("  No results — done.");
                break;
            }

            // Detect total pages
            if ($totalPages === null && ! empty($json['count'])) {
                $totalPages = (int) ceil($json['count'] / $pageSize);
                $this->info("  Total cases: {$json['count']} | Total pages: {$totalPages}");
            }

            // Process each case on this page
            foreach ($json['results'] as $case) {
                $externalId = (int) $case['id'];

                // Skip if already in DB
                $exists = DB::connection('pgvector')
                    ->table('german_cases')
                    ->where('external_id', $externalId)
                    ->exists();

                if ($exists) {
                    $skipped++;
                    continue;
                }

                // Fetch full text from detail endpoint
                $detail = $this->fetchDetail($externalId);
                $content = $detail ? $this->stripHtml($detail['content'] ?? '') : null;
                usleep(500000); // 0.5s between detail requests

                $court = $case['court'] ?? [];

                DB::connection('pgvector')->table('german_cases')->insert([
                    'external_id'     => $externalId,
                    'slug'            => $case['slug'] ?? null,
                    'file_number'     => $case['file_number'] ?? null,
                    'date'            => $case['date'] ?? null,
                    'decision_type'   => $case['type'] ?? null,
                    'ecli'            => $case['ecli'] ?? null,
                    'court_name'      => $court['name'] ?? null,
                    'court_slug'      => $court['slug'] ?? null,
                    'jurisdiction'    => $court['jurisdiction'] ?? null,
                    'level_of_appeal' => $court['level_of_appeal'] ?? null,
                    'content'         => $content,
                    'content_hash'    => $content ? md5($content) : null,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);

                // Mark in queue as done
                DB::connection('pgvector')->table('german_cases_queue')->upsert(
                    ['external_id' => $externalId, 'status' => 'done', 'fetched_at' => now(), 'queued_at' => now()],
                    ['external_id'],
                    ['status', 'fetched_at']
                );

                $inserted++;
            }

            $this->saveProgress($page);

            // Use next URL from API response — reliable cursor pagination
            $nextUrl = $json['next'] ?? null;

            if (! $nextUrl) {
                $this->info("  All pages fetched!");
                break;
            }

            $this->line("  Next: {$nextUrl}");

            $page++;
            if ($delay > 0) sleep($delay);
        }

        $this->clearProgress();

        $total = DB::connection('pgvector')->table('german_cases')->count();
        $this->info("Done. Total in DB: {$total} | Inserted: {$inserted} | Skipped: {$skipped}");

        Log::info('german:fetch done', compact('inserted', 'skipped', 'total'));

        return 0;
    }

    private function fetchDetail(int $id): ?array
    {
        $retries = 3;
        $wait    = 15;

        for ($i = 0; $i < $retries; $i++) {
            try {
                $response = Http::timeout(self::TIMEOUT)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (compatible; LegalResearch/1.0)',
                        'Accept'     => 'application/json',
                    ])
                    ->get(self::API_BASE . "/cases/{$id}/", ['format' => 'json']);

                if ($response->ok()) {
                    return $response->json();
                }

                if ($response->status() === 429) {
                    $this->warn("    Detail 429 for {$id} — waiting {$wait}s");
                    sleep($wait);
                    $wait *= 2;
                    continue;
                }
            } catch (\Exception $e) {
                Log::warning("german:fetch detail failed for {$id}", ['error' => $e->getMessage()]);
                sleep(5);
            }
        }

        return null;
    }

    private function stripHtml(string $html): string
    {
        // Decode HTML entities, strip tags, collapse whitespace
        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    private function saveProgress(int $page): void
    {
        file_put_contents(
            base_path(self::PROGRESS_FILE),
            json_encode(['page' => $page, 'saved_at' => now()->toDateTimeString()])
        );
    }

    private function loadProgress(): int
    {
        $path = base_path(self::PROGRESS_FILE);

        if (! file_exists($path)) {
            return 1;
        }

        $data = json_decode(file_get_contents($path), true);
        $page = (int) ($data['page'] ?? 1);

        if ($page > 1) {
            $this->info("Auto-resuming from page {$page} (saved at {$data['saved_at']})");
        }

        return max(1, $page);
    }

    private function clearProgress(): void
    {
        $path = base_path(self::PROGRESS_FILE);
        if (file_exists($path)) {
            unlink($path);
        }
    }
}
