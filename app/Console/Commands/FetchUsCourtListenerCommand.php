<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;

/**
 * Fetches US Supreme Court opinions from CourtListener API.
 * Uses /opinions/ endpoint — directly contains plain_text, no second API call needed.
 * Resumable — skips already-imported cluster IDs.
 *
 * Usage:
 *   php artisan us:fetch-courtlistener
 *   php artisan us:fetch-courtlistener --limit=10 --debug
 */
class FetchUsCourtListenerCommand extends Command
{
    protected $signature = 'us:fetch-courtlistener
        {--limit=0  : Max cases to insert (0 = all)}
        {--delay=1  : Seconds between page requests}
        {--debug    : Print first result raw JSON}';

    protected $description = 'Fetch US Supreme Court opinions from CourtListener into us_cases';

    private const BASE_URL = 'https://www.courtlistener.com/api/rest/v3';

    public function handle(): int
    {
        $token = env('COURTLISTENER_TOKEN');
        if (! $token) {
            $this->error('COURTLISTENER_TOKEN not set in .env');
            return 1;
        }

        $limit   = (int) $this->option('limit');
        $delay   = (int) $this->option('delay');
        $debug   = (bool) $this->option('debug');
        $client  = new GuzzleClient(['timeout' => 90]);
        $headers = ['Authorization' => "Token {$token}"];

        $inserted = 0;
        $skipped  = 0;
        $empty    = 0;
        $retries  = 0;

        // opinions endpoint: one row per opinion, has plain_text directly
        // filter: SCOTUS court + non-empty text + only majority/lead opinions
        $nextUrl = self::BASE_URL . '/opinions/?cluster__docket__court=scotus&type=010combined&page_size=100';

        $this->info("Fetching SCOTUS opinions from CourtListener...");
        $this->newLine();

        while ($nextUrl) {
            try {
                $response = $client->get($nextUrl, ['headers' => $headers]);
                $data     = json_decode($response->getBody()->getContents(), true);
                $retries  = 0;
            } catch (\Throwable $e) {
                $retries++;
                $wait = min(120, $retries * 20);
                $this->error("Request failed (#{$retries}): " . $e->getMessage());
                if ($retries >= 5) { $this->error("Too many failures."); break; }
                sleep($wait);
                continue;
            }

            if ($debug) {
                $this->line(json_encode($data['results'][0] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $debug = false;
            }

            $count   = $data['count'] ?? 0;
            $nextUrl = $data['next'] ?? null;
            $results = $data['results'] ?? [];

            if ($inserted + $skipped + $empty === 0) {
                $this->info("Total available: {$count}");
            }

            foreach ($results as $opinion) {
                $clusterId = $this->extractId((string) ($opinion['cluster'] ?? ''));
                if (! $clusterId) continue;

                // Use cluster ID as external_id (one case = one majority opinion)
                $exists = DB::connection('pgvector')
                    ->table('us_cases')
                    ->where('external_id', $clusterId)
                    ->exists();

                if ($exists) { $skipped++; continue; }

                $text = trim($opinion['plain_text'] ?? '');
                if (empty($text) && ! empty($opinion['html'])) {
                    $text = trim(strip_tags($opinion['html']));
                }

                if (empty($text)) { $empty++; continue; }

                $text = preg_replace('/\s+/', ' ', $text);

                // Fetch cluster metadata for case name / date / citation
                $meta = $this->fetchCluster($client, $headers, (string) ($opinion['cluster'] ?? ''));

                $date     = $this->parseDate($meta['date_filed'] ?? null);
                $year     = $date ? (int) substr($date, 0, 4) : null;
                $citation = $this->firstCitation($meta['citations'] ?? []);

                DB::connection('pgvector')->table('us_cases')->insert([
                    'external_id'       => $clusterId,
                    'name_abbreviation' => mb_substr((string) ($meta['case_name'] ?? ''), 0, 500) ?: null,
                    'name'              => mb_substr((string) ($meta['case_name_full'] ?? ''), 0, 2000) ?: null,
                    'citation'          => $citation,
                    'decision_date'     => $date,
                    'decision_year'     => $year,
                    'court_name'        => 'Supreme Court of the United States',
                    'jurisdiction'      => 'U.S.',
                    'volume'            => null,
                    'reporter'          => null,
                    'content'           => $text,
                    'content_hash'      => md5($text),
                    'created_at'        => now(),
                    'updated_at'        => now(),
                ]);

                $inserted++;

                if ($inserted % 25 === 0) {
                    $this->line("Inserted: {$inserted} | Skipped: {$skipped} | Empty: {$empty}");
                }

                if ($limit > 0 && $inserted >= $limit) {
                    $this->info("\nLimit reached ({$limit}).");
                    break 2;
                }
            }

            $this->line("Page done — Inserted: {$inserted} | Skipped: {$skipped} | Empty: {$empty}");

            if ($nextUrl && $delay > 0) sleep($delay);
        }

        $dbTotal = DB::connection('pgvector')->table('us_cases')->count();
        $this->info("\nDone! Inserted: {$inserted} | Total in DB: {$dbTotal}");

        return 0;
    }

    private function fetchCluster(GuzzleClient $client, array $headers, string $url): array
    {
        if (empty($url)) return [];
        try {
            $r = $client->get($url, ['headers' => $headers]);
            return json_decode($r->getBody()->getContents(), true) ?? [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function extractId(string $url): ?string
    {
        if (preg_match('/\/(\d+)\/?$/', $url, $m)) {
            return $m[1];
        }
        return null;
    }

    private function parseDate(mixed $value): ?string
    {
        if (empty($value)) return null;
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $value, $m)) return $m[0];
        return null;
    }

    private function firstCitation(array $citations): ?string
    {
        foreach ($citations as $c) {
            $cite = $c['cite'] ?? $c['citation'] ?? null;
            if ($cite) return mb_substr((string) $cite, 0, 200);
        }
        return null;
    }
}
