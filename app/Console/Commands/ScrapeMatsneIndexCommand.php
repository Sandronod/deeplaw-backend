<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Scrapes the full Matsne document index (all types) into matsne_doc_queue.
 * Safe to stop and re-run — already-queued IDs are skipped.
 *
 * Usage:
 *   php artisan matsne:scrape-index
 *   php artisan matsne:scrape-index --delay=3 --start-page=500
 */
class ScrapeMatsneIndexCommand extends Command
{
    protected $signature = 'matsne:scrape-index
        {--delay=3        : Seconds between requests}
        {--start-page=1   : Resume from this page}
        {--max-pages=0    : Stop after N pages (0 = unlimited)}
        {--per-page=20    : Results per page (Matsne default is 20)}
        {--group=         : Matsne document group filter (e.g. 1000003=კანონი)}
        {--doc-type=      : Label to store in doc_type column when group is set}';

    protected $description = 'Scrape all Matsne document IDs into matsne_doc_queue (resumable)';

    private const SEARCH_URL  = 'https://matsne.gov.ge/ka/document/search';
    private const TIMEOUT     = 25;
    private const PROGRESS_FILE = 'storage/matsne_scrape_progress.json';

    public function handle(): int
    {
        $delay     = max(1, (int) $this->option('delay'));
        $maxPages  = (int) $this->option('max-pages');
        $group     = $this->option('group') ?: null;
        $docType   = $this->option('doc-type') ?: null;

        // Auto-resume: load last saved page unless --start-page explicitly given
        $explicitStart = (int) $this->option('start-page');
        $startPage = $explicitStart > 1 ? $explicitStart : $this->loadProgress();

        $label = $group ? " [group={$group}" . ($docType ? ", type={$docType}" : '') . "]" : " (all types)";
        $this->info("Scraping Matsne index{$label}...");
        $this->info("Start page: {$startPage} | Delay: {$delay}s | Max pages: " . ($maxPages ?: 'unlimited'));

        $page      = $startPage;
        $inserted  = 0;
        $skipped   = 0;
        $lastPage  = null;

        while (true) {
            if ($maxPages > 0 && ($page - $startPage) >= $maxPages) {
                $this->line("Reached max-pages limit ({$maxPages}), stopping.");
                break;
            }

            $this->line("  Page {$page}" . ($lastPage ? "/{$lastPage}" : '') . " ...");

            try {
                $params = [
                    'type'    => 'all',
                    'geo'     => 'on',
                    'is-ajax' => '1',
                    'page'    => $page,
                ];
                if ($group) {
                    $params['group'] = $group;
                }

                $response = Http::withHeaders([
                    'User-Agent'       => 'LegalCopilot/1.0 (legal research assistant)',
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Accept'           => 'application/json, text/javascript, */*',
                    'Referer'          => 'https://matsne.gov.ge/ka/document/search',
                ])->timeout(self::TIMEOUT)->get(self::SEARCH_URL, $params);
            } catch (\Exception $e) {
                $this->warn("  Request failed: " . $e->getMessage() . " — retrying in 10s");
                sleep(10);
                continue;
            }

            if (in_array($response->status(), [403, 404])) {
                $this->warn("  HTTP {$response->status()} — stopping.");
                break;
            }

            if ($response->failed()) {
                $this->warn("  HTTP {$response->status()} — skipping page, retrying in {$delay}s");
                sleep($delay);
                $page++;
                continue;
            }

            $json = $response->json();

            if (empty($json['documents_list'])) {
                $this->line("  Empty documents_list on page {$page} — done.");
                break;
            }

            $docs = $this->extractDocuments($json['documents_list'], $docType);

            if (empty($docs)) {
                $this->line("  No documents parsed on page {$page} — done.");
                break;
            }

            // Batch upsert — skip existing IDs
            $existingIds = DB::connection('pgvector')
                ->table('matsne_doc_queue')
                ->whereIn('matsne_id', array_column($docs, 'matsne_id'))
                ->pluck('matsne_id')
                ->flip()
                ->all();

            $toInsert = array_filter($docs, fn($d) => ! isset($existingIds[$d['matsne_id']]));

            if (! empty($toInsert)) {
                DB::connection('pgvector')->table('matsne_doc_queue')->insert(array_values($toInsert));
                $inserted += count($toInsert);
            }

            $skipped += count($docs) - count($toInsert);

            $this->line("  +{$inserted} inserted, {$skipped} skipped (page has " . count($docs) . " docs)");

            // Detect last page
            if (! empty($json['pagination'])) {
                $detected = $this->parseTotalPages($json['pagination']);
                if ($detected) {
                    $lastPage = $detected;
                    if ($page >= $lastPage) {
                        $this->info("  Reached last page ({$lastPage}). Done!");
                        break;
                    }
                }
            }

            $this->saveProgress($page);
            $page++;
            sleep($delay);
        }

        $this->clearProgress();

        $total   = DB::connection('pgvector')->table('matsne_doc_queue')->count();
        $pending = DB::connection('pgvector')->table('matsne_doc_queue')->where('status', 'pending')->count();

        $this->info("Scraping complete. Queue total: {$total} | Pending: {$pending}");
        Log::info('matsne:scrape-index done', compact('inserted', 'skipped', 'page', 'total'));

        return 0;
    }

    private function extractDocuments(string $html, ?string $docTypeOverride = null): array
    {
        $results = [];

        preg_match_all(
            '|href=["\'/]+ka/document/view/(\d+)[^"\']*["\'"][^>]*>\s*([^<]+)\s*</a|u',
            $html,
            $matches,
            PREG_SET_ORDER
        );

        // Also try to extract document type from surrounding HTML
        // Matsne wraps each result in a <div class="views-row"> with type info
        preg_match_all(
            '|<span[^>]+class=["\'][^"\']*document-type[^"\']*["\'][^>]*>\s*([^<]+)\s*</span|u',
            $html,
            $typeMatches
        );

        $types = $typeMatches[1] ?? [];

        foreach ($matches as $i => $match) {
            $id    = (int) $match[1];
            $title = trim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $type  = $docTypeOverride
                ?? (isset($types[$i]) ? trim(html_entity_decode($types[$i], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : null);

            if ($id > 0 && mb_strlen($title) >= 2) {
                $results[] = [
                    'matsne_id'  => $id,
                    'title'      => mb_substr($title, 0, 1000),
                    'doc_type'   => $type ? mb_substr($type, 0, 100) : null,
                    'status'     => 'pending',
                    'queued_at'  => now(),
                ];
            }
        }

        // Deduplicate by matsne_id within this page
        $unique = [];
        foreach ($results as $r) {
            $unique[$r['matsne_id']] = $r;
        }

        return array_values($unique);
    }

    private function parseTotalPages(string $paginationHtml): ?int
    {
        preg_match_all('/[?&]page=(\d+)/', $paginationHtml, $matches);

        if (empty($matches[1])) {
            return null;
        }

        return (int) max($matches[1]);
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
