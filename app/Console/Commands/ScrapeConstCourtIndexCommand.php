<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Scrapes Constitutional Court judicial acts index into const_court_queue.
 * Safe to re-run — existing IDs are skipped.
 *
 * Usage:
 *   php artisan constcourt:scrape-index
 *   php artisan constcourt:scrape-index --delay=3 --start-page=50
 */
class ScrapeConstCourtIndexCommand extends Command
{
    protected $signature = 'constcourt:scrape-index
        {--delay=2       : Seconds between page requests}
        {--start-page=1  : Resume from this page}
        {--max-pages=0   : Stop after N pages (0=unlimited)}';

    protected $description = 'Scrape Constitutional Court document index into const_court_queue';

    private const BASE_URL = 'https://constcourt.ge/ka/judicial-acts';
    private const PER_PAGE = 100;

    public function handle(): int
    {
        $delay     = max(1, (int) $this->option('delay'));
        $startPage = max(1, (int) $this->option('start-page'));
        $maxPages  = (int) $this->option('max-pages');

        $this->info("Scraping Constitutional Court index (constcourt.ge)...");
        $this->info("Start page: {$startPage} | Per page: " . self::PER_PAGE . " | Delay: {$delay}s");

        $page     = $startPage;
        $inserted = 0;
        $skipped  = 0;

        while (true) {
            if ($maxPages > 0 && ($page - $startPage) >= $maxPages) {
                $this->line("Reached max-pages ({$maxPages}), stopping.");
                break;
            }

            $this->line("  Page {$page}...");

            try {
                $response = Http::withHeaders([
                    'User-Agent' => 'LegalCopilot/1.0 (legal research assistant)',
                    'Accept'     => 'text/html,application/xhtml+xml',
                ])->timeout(30)->get(self::BASE_URL, [
                    'quantity' => self::PER_PAGE,
                    'page'     => $page,
                    'sort'     => 'asc',
                ]);
            } catch (\Exception $e) {
                $this->warn("  Request failed: " . $e->getMessage() . " — retrying in 10s");
                sleep(10);
                continue;
            }

            if ($response->failed()) {
                $this->warn("  HTTP {$response->status()} on page {$page} — skipping");
                sleep($delay);
                $page++;
                continue;
            }

            $docs = $this->parseListPage($response->body());

            if (empty($docs)) {
                $this->info("No documents on page {$page} — done.");
                break;
            }

            // Batch upsert — skip existing
            $existingIds = DB::connection('pgvector')
                ->table('const_court_queue')
                ->whereIn('legal_id', array_column($docs, 'legal_id'))
                ->pluck('legal_id')
                ->flip()
                ->all();

            $toInsert = array_filter($docs, fn($d) => !isset($existingIds[$d['legal_id']]));

            if (!empty($toInsert)) {
                DB::connection('pgvector')->table('const_court_queue')->insert(array_values($toInsert));
                $inserted += count($toInsert);
            }
            $skipped += count($docs) - count($toInsert);

            $this->line("  Page {$page}: " . count($docs) . " docs | +{$inserted} new | {$skipped} existing");

            // Last page detection
            if (count($docs) < self::PER_PAGE) {
                $this->info("Last page reached (fewer than " . self::PER_PAGE . " results). Done!");
                break;
            }

            $page++;
            sleep($delay);
        }

        $total   = DB::connection('pgvector')->table('const_court_queue')->count();
        $pending = DB::connection('pgvector')->table('const_court_queue')->where('status', 'pending')->count();

        $this->info("Scraping complete. Queue total: {$total} | Pending: {$pending} | Inserted this run: {$inserted}");
        Log::info('constcourt:scrape-index done', compact('inserted', 'skipped', 'page', 'total'));

        return 0;
    }

    // ── HTML parsing ──────────────────────────────────────────────────────────

    private function parseListPage(string $html): array
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $docs  = [];

        // Each decision title: <h5 class="legal-act-title"><a href="...?legal=ID">TITLE</a></h5>
        $links = $xpath->query('//h5[contains(@class,"legal-act-title")]//a[@href]');

        foreach ($links as $link) {
            $href = $link->getAttribute('href');
            if (!preg_match('/[?&]legal=(\d+)/', $href, $m)) continue;
            $legalId = (int) $m[1];
            if ($legalId <= 0) continue;

            $title = preg_replace('/\s+/', ' ', trim($link->textContent));

            // Decision type from sibling <li title="...">
            $li   = $xpath->query('ancestor::div[contains(@class,"legal-act")]//li[@title]', $link)->item(0);
            $type = $li ? trim($li->getAttribute('title')) : null;

            $docs[$legalId] = [
                'legal_id'      => $legalId,
                'title'         => mb_substr($title, 0, 1000),
                'decision_type' => $type ? mb_substr($type, 0, 100) : null,
                'status'        => 'pending',
                'queued_at'     => now(),
            ];
        }

        return array_values($docs);
    }
}
