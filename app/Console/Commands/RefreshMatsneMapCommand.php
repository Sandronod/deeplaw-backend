<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RefreshMatsneMapCommand extends Command
{
    protected $signature   = 'matsne:refresh-map {--pages=60 : Max pages to scan} {--delay=12 : Seconds between requests}';
    protected $description = 'Refresh matsne_laws_map.json from Matsne search (group=კანონი, type=main)';

    private const SEARCH_URL  = 'https://matsne.gov.ge/ka/document/search';
    private const MAP_PATH    = 'database/seeds/matsne_laws_map.json';
    private const GROUP_LAW   = '1000003'; // კანონი
    private const TIMEOUT     = 20;

    public function handle(): int
    {
        $maxPages = (int) $this->option('pages');
        $delay    = (int) $this->option('delay');

        $this->info("Refreshing Matsne law map (max {$maxPages} pages, {$delay}s delay)...");

        $existing = $this->loadMap();
        $found    = [];
        $page     = 1;

        while ($page <= $maxPages) {
            $this->line("  Page {$page}...");

            try {
                $response = Http::withHeaders([
                    'User-Agent'       => 'LegalCopilot/1.0 (legal research assistant)',
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Accept'           => 'application/json, text/javascript, */*',
                    'Referer'          => 'https://matsne.gov.ge/ka/document/search',
                ])->timeout(self::TIMEOUT)->get(self::SEARCH_URL, [
                    'group'   => self::GROUP_LAW,
                    'type'    => 'main',
                    'is-ajax' => '1',
                    'page'    => $page,
                ]);
            } catch (\Exception $e) {
                $this->warn("  Request failed on page {$page}: " . $e->getMessage());
                break;
            }

            if ($response->status() === 403 || $response->status() === 404) {
                $this->warn("  HTTP {$response->status()} on page {$page}, stopping.");
                break;
            }

            if ($response->failed()) {
                $this->warn("  HTTP {$response->status()} on page {$page}, skipping.");
                sleep($delay);
                $page++;
                continue;
            }

            $json = $response->json();

            if (!isset($json['documents_list'])) {
                $this->warn("  No documents_list on page {$page}, stopping.");
                break;
            }

            $html      = $json['documents_list'];
            $extracted = $this->extractDocuments($html);

            if (empty($extracted)) {
                $this->line("  No documents found on page {$page}, stopping.");
                break;
            }

            foreach ($extracted as [$id, $title]) {
                $found[$title] = $id;

                // Also add short version: strip leading "საქართველოს "
                $short = preg_replace('/^საქართველოს\s+/u', '', $title);
                if ($short !== $title) {
                    $found[$short] = $id;
                }
            }

            $this->line("  Found " . count($extracted) . " documents (total: " . count($found) . ")");

            // Check pagination — stop if last page
            if (isset($json['pagination'])) {
                $totalPages = $this->parseTotalPages($json['pagination']);
                if ($totalPages && $page >= $totalPages) {
                    $this->line("  Reached last page ({$totalPages}).");
                    break;
                }
            }

            $page++;
            sleep($delay);
        }

        if (empty($found)) {
            $this->error('No laws found. Map not updated.');
            return 1;
        }

        // Merge: existing manually curated entries take precedence over scraped data
        $merged = array_merge($found, $existing);
        ksort($merged);

        $this->saveMap($merged);

        $added = count($merged) - count($existing);
        $this->info("Done. Total entries: " . count($merged) . " (+{$added} new).");

        Log::info('matsne:refresh-map completed', [
            'total'  => count($merged),
            'added'  => $added,
            'pages'  => $page - 1,
        ]);

        return 0;
    }

    /**
     * Extract (id, title) pairs from the documents_list HTML fragment.
     * Links look like: <a href="/ka/document/view/31702">საქართველოს სამოქალაქო კოდექსი</a>
     */
    private function extractDocuments(string $html): array
    {
        $results = [];

        // Match all document view links
        preg_match_all(
            '|href=["\'/]+ka/document/view/(\d+)[^"\']*["\'][^>]*>\s*([^<]+)\s*</a|u',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $id    = (int) $match[1];
            $title = trim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($id > 0 && mb_strlen($title) > 3) {
                $results[] = [$id, $title];
            }
        }

        return $results;
    }

    /**
     * Parse total pages from pagination HTML fragment.
     * Looks for the last page number in pagination links.
     */
    private function parseTotalPages(string $paginationHtml): ?int
    {
        preg_match_all('/[?&]page=(\d+)/', $paginationHtml, $matches);

        if (empty($matches[1])) {
            return null;
        }

        return (int) max($matches[1]);
    }

    private function loadMap(): array
    {
        $path = base_path(self::MAP_PATH);
        if (!file_exists($path)) {
            return [];
        }
        return json_decode(file_get_contents($path), true) ?? [];
    }

    private function saveMap(array $map): void
    {
        $path    = base_path(self::MAP_PATH);
        $content = json_encode($map, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        file_put_contents($path, $content);
    }
}
