<?php

namespace App\Services\Matsne;

use Illuminate\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Atomic fetch lock for Matsne law fetching.
 * Prevents duplicate fetches when multiple requests come in simultaneously
 * for the same law.
 */
class FetchLockService
{
    private const TTL_SECONDS = 300; // 5 minutes — max time a fetch should take

    /**
     * Try to acquire an exclusive fetch lock for a matsne_id.
     * Returns the Lock instance if acquired, null if someone else holds it.
     */
    public function acquire(int $matsneId): ?Lock
    {
        $lock = Cache::lock($this->lockKey($matsneId), self::TTL_SECONDS);

        if ($lock->get()) {
            Log::debug('FetchLockService: lock acquired', ['matsne_id' => $matsneId]);
            return $lock;
        }

        Log::debug('FetchLockService: lock busy', ['matsne_id' => $matsneId]);
        return null;
    }

    /**
     * Check if a fetch is currently in progress (lock held by another process).
     */
    public function isLocked(int $matsneId): bool
    {
        // Try a zero-TTL lock — if it fails, lock is held
        $probe = Cache::lock($this->lockKey($matsneId), 1);
        if ($probe->get()) {
            $probe->release();
            return false;
        }
        return true;
    }

    /**
     * Mark a law as "queued for fetch" so duplicate dispatches are prevented.
     */
    public function markQueued(int $matsneId): void
    {
        Cache::put($this->queueKey($matsneId), 1, self::TTL_SECONDS);
    }

    public function isQueued(int $matsneId): bool
    {
        return Cache::has($this->queueKey($matsneId));
    }

    public function unmarkQueued(int $matsneId): void
    {
        Cache::forget($this->queueKey($matsneId));
    }

    /**
     * Poll until the fetch lock is released or timeout is reached.
     * Returns true if lock was released, false if timed out.
     */
    public function waitUntilReleased(int $matsneId, int $maxSeconds = 6): bool
    {
        $elapsed = 0;

        while ($elapsed < $maxSeconds) {
            sleep(1);
            $elapsed++;

            if (!$this->isLocked($matsneId)) {
                Log::debug('FetchLockService: lock released after wait', [
                    'matsne_id' => $matsneId,
                    'waited_s'  => $elapsed,
                ]);
                return true;
            }
        }

        Log::debug('FetchLockService: wait timed out', [
            'matsne_id'  => $matsneId,
            'max_seconds' => $maxSeconds,
        ]);
        return false;
    }

    private function lockKey(int $id): string  { return "matsne_fetch_lock:{$id}"; }
    private function queueKey(int $id): string { return "matsne_fetch_queued:{$id}"; }
}
