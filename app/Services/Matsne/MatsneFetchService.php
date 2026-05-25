<?php

namespace App\Services\Matsne;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class MatsneFetchService
{
    private const BASE_URL = 'https://matsne.gov.ge/ka/document/view';
    private const TIMEOUT  = 30;
    private const RETRIES  = 2;

    /**
     * Fetch raw HTML for a Matsne document.
     * Retries up to 2 times on server errors (5xx).
     * Rate limiting is handled externally by ExternalSourceRateLimiter.
     *
     * @throws RuntimeException on permanent failure
     */
    public function fetchHtml(int $matsneId): string
    {
        $url = self::BASE_URL . "/{$matsneId}/0?publication=0";

        Log::info('MatsneFetchService: fetching', ['url' => $url]);

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'LegalCopilot/1.0 (legal research assistant)',
                'Accept'     => 'text/html,application/xhtml+xml',
            ])
                ->timeout(self::TIMEOUT)
                ->retry(self::RETRIES, 5000, fn($e) =>
                    $e instanceof \Illuminate\Http\Client\RequestException &&
                    in_array($e->response->status(), [500, 502, 503, 504])
                )
                ->get($url);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new RuntimeException(
                "Matsne connection failed for ID {$matsneId}: " . $e->getMessage(), 0, $e
            );
        }

        if ($response->status() === 403 || $response->status() === 404) {
            throw new RuntimeException(
                "Matsne: document {$matsneId} not accessible (HTTP {$response->status()})"
            );
        }

        if ($response->failed()) {
            throw new RuntimeException(
                "Matsne fetch failed for ID {$matsneId}: HTTP {$response->status()}"
            );
        }

        $html = $response->body();

        if (empty($html) || mb_strlen($html) < 500) {
            throw new RuntimeException("Matsne returned empty body for ID {$matsneId}");
        }

        Log::info('MatsneFetchService: fetched', [
            'matsne_id' => $matsneId,
            'bytes'     => mb_strlen($html),
        ]);

        return $html;
    }

    /**
     * Resolve law name → matsne_id using the static map.
     */
    public function resolveId(string $lawName): ?int
    {
        $map   = $this->loadMap();
        $lower = mb_strtolower(trim($lawName));

        // Exact match
        if (isset($map[$lawName])) {
            return (int) $map[$lawName];
        }

        // Case-insensitive exact
        foreach ($map as $name => $id) {
            if (mb_strtolower($name) === $lower) {
                return (int) $id;
            }
        }

        // Substring match — query contains map key or vice versa
        foreach ($map as $name => $id) {
            $mapLower = mb_strtolower($name);
            if (str_contains($lower, $mapLower) || str_contains($mapLower, $lower)) {
                return (int) $id;
            }
        }

        return null;
    }

    public function loadMap(): array
    {
        $path = database_path('seeds/matsne_laws_map.json');
        if (!file_exists($path)) {
            return [];
        }
        return json_decode(file_get_contents($path), true) ?? [];
    }
}
