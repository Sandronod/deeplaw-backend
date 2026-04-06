<?php

namespace App\Services\Matsne;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Redis-based rate limiter for external HTTP sources.
 * Uses a simple "last request timestamp" approach with Cache::lock()
 * to ensure only one request runs at a time per domain.
 */
class ExternalSourceRateLimiter
{
    private const LIMITS = [
        'matsne.gov.ge'      => 12, // seconds between requests (robots.txt: 10 + 2 buffer)
        'hudoc.echr.coe.int' => 2,  // ECHR portal — 2 seconds is conservative and safe
    ];

    private const DEFAULT_DELAY = 5;
    private const MAX_WAIT_SEC  = 60;

    /**
     * Throttle a callback to respect per-domain rate limits.
     * Uses an atomic lock so only one process fetches at a time.
     *
     * @throws \RuntimeException if max wait exceeded
     */
    public function throttle(string $domain, callable $callback): mixed
    {
        $delay   = self::LIMITS[$domain] ?? self::DEFAULT_DELAY;
        $lockKey = "rate_limiter_lock:{$domain}";

        // Atomic lock — only one process at a time per domain
        $lock = Cache::lock($lockKey, $delay + 5);

        $waited = 0;
        while (!$lock->get()) {
            if ($waited >= self::MAX_WAIT_SEC) {
                throw new \RuntimeException("Rate limiter: max wait exceeded for {$domain}");
            }
            sleep(1);
            $waited++;
        }

        try {
            // Enforce minimum delay since last request
            $lastKey     = "rate_limiter_last:{$domain}";
            $lastRequest = Cache::get($lastKey, 0);
            $elapsed     = time() - $lastRequest;

            if ($elapsed < $delay) {
                $wait = $delay - $elapsed;
                Log::debug('ExternalSourceRateLimiter: waiting', [
                    'domain'  => $domain,
                    'seconds' => $wait,
                ]);
                sleep($wait);
            }

            Cache::put($lastKey, time(), 300);

            return $callback();

        } finally {
            $lock->release();
        }
    }
}
