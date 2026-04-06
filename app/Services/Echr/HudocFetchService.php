<?php

namespace App\Services\Echr;

use App\Services\Matsne\ExternalSourceRateLimiter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches the full text of a single HUDOC case document.
 * Rate-limited via ExternalSourceRateLimiter (2s between requests).
 */
class HudocFetchService
{
    private const BASE_URL = 'https://hudoc.echr.coe.int';
    private const DOMAIN   = 'hudoc.echr.coe.int';
    private const TIMEOUT  = 20;

    public function __construct(
        private readonly ExternalSourceRateLimiter $rateLimiter,
    ) {}

    /**
     * Fetch full HTML text for a case by hudoc_itemid.
     * Returns cleaned plain-text string, or null on failure.
     */
    public function fetchText(string $itemId): ?string
    {
        try {
            $html = $this->rateLimiter->throttle(self::DOMAIN, function () use ($itemId) {
                $response = Http::timeout(self::TIMEOUT)
                    ->withHeaders(['Accept' => 'text/html,application/xhtml+xml'])
                    ->get(self::BASE_URL . '/app/conversion/docx/html/body', [
                        'library' => 'ECHR',
                        'id'      => $itemId,
                    ]);

                if ($response->failed()) {
                    Log::warning('HudocFetchService: HTTP error fetching text', [
                        'item_id' => $itemId,
                        'status'  => $response->status(),
                    ]);
                    return null;
                }

                return $response->body();
            });

            if (empty($html)) {
                return null;
            }

            return $this->htmlToText($html);

        } catch (\Throwable $e) {
            Log::error('HudocFetchService: exception fetching text', [
                'item_id' => $itemId,
                'error'   => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Convert HUDOC HTML to clean plain text suitable for embedding.
     */
    private function htmlToText(string $html): string
    {
        // Remove script / style blocks
        $html = preg_replace('/<(script|style)[^>]*>.*?<\/\1>/is', '', $html);

        // Block-level elements → newline
        $html = preg_replace('/<\/(p|div|li|tr|h[1-6]|br)>/i', "\n", $html);
        $html = preg_replace('/<(br|hr)\s*\/?>/i', "\n", $html);

        // Strip remaining tags
        $text = strip_tags($html);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Collapse excessive whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }
}
