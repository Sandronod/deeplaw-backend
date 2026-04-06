<?php

namespace App\Services\Echr;

use Illuminate\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Atomic fetch lock for HUDOC case fetching.
 * Prevents duplicate concurrent fetches for the same hudoc_itemid or topic query.
 */
class EchrFetchLockService
{
    private const TTL_SECONDS = 120; // max time a single HUDOC fetch should take

    /**
     * Try to acquire an exclusive lock for a given key (itemid or query hash).
     * Returns Lock on success, null if already held.
     */
    public function acquire(string $key): ?Lock
    {
        $lock = Cache::lock($this->lockKey($key), self::TTL_SECONDS);

        if ($lock->get()) {
            Log::debug('EchrFetchLockService: lock acquired', ['key' => $key]);
            return $lock;
        }

        Log::debug('EchrFetchLockService: lock busy', ['key' => $key]);
        return null;
    }

    public function isLocked(string $key): bool
    {
        $probe = Cache::lock($this->lockKey($key), 1);
        if ($probe->get()) {
            $probe->release();
            return false;
        }
        return true;
    }

    public function markQueued(string $key): void
    {
        Cache::put($this->queueKey($key), 1, self::TTL_SECONDS);
    }

    public function isQueued(string $key): bool
    {
        return Cache::has($this->queueKey($key));
    }

    public function unmarkQueued(string $key): void
    {
        Cache::forget($this->queueKey($key));
    }

    /**
     * Poll until the lock is released or timeout is reached.
     */
    public function waitUntilReleased(string $key, int $maxSeconds = 8): bool
    {
        $elapsed = 0;

        while ($elapsed < $maxSeconds) {
            sleep(1);
            $elapsed++;

            if (!$this->isLocked($key)) {
                Log::debug('EchrFetchLockService: lock released after wait', [
                    'key'      => $key,
                    'waited_s' => $elapsed,
                ]);
                return true;
            }
        }

        Log::debug('EchrFetchLockService: wait timed out', [
            'key'         => $key,
            'max_seconds' => $maxSeconds,
        ]);
        return false;
    }

    private function lockKey(string $key): string  { return "echr_fetch_lock:{$key}"; }
    private function queueKey(string $key): string { return "echr_fetch_queued:{$key}"; }
}
