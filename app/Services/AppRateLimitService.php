<?php

namespace App\Services;

use App\Utils\CacheKey;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Fixed-window budgets for public endpoints and authentication.
 *
 * Follows the codebase's existing Cache-based rate-limit style
 * (see RegisterService's REGISTER_IP_RATE_LIMIT). The IP passed in is
 * the framework-resolved client IP ($request->ip()); TrustProxies is
 * already configured for the CDN ranges in front of the panel, so
 * this is the real client IP, not a CDN node address.
 */
class AppRateLimitService
{
    /**
     * Record one attempt and report whether it stays within the limit.
     *
     * @param string $bucket     CacheKey bucket name
     * @param string $ip         Client IP
     * @param int    $maxAttempts Maximum attempts allowed per window
     * @param int    $decaySeconds Window length in seconds
     */
    public function attempt(string $bucket, string $ip, int $maxAttempts, int $decaySeconds = 3600): bool
    {
        $key = CacheKey::get($bucket, $ip);
        return $this->reserve($key, [$key => $maxAttempts], $decaySeconds);
    }

    /**
     * Reserve all budgets together after checking each cap under the same lock.
     *
     * @param array<string, int> $limits Cache keys and their caps
     */
    public function reserve(string $scope, array $limits, int $decaySeconds): bool
    {
        try {
            return $this->lock($scope)
                ->block(1, function () use ($limits, $decaySeconds) {
                    foreach ($limits as $key => $maxAttempts) {
                        if ((int) Cache::get($key, 0) >= $maxAttempts) {
                            return false;
                        }
                    }

                    foreach ($limits as $key => $maxAttempts) {
                        if ($this->increment($key, $decaySeconds) < 1) {
                            return false;
                        }
                    }

                    return true;
                });
        } catch (LockTimeoutException) {
            return false;
        }
    }

    /**
     * Record one hit in a fixed window and return the running count.
     *
     * @param string $bucket      CacheKey bucket name
     * @param string $key         Window identity (IP, domain, 'ALL', ...)
     * @param int    $decaySeconds Window length in seconds
     */
    public function hit(string $bucket, string $key, int $decaySeconds = 3600): int
    {
        $cacheKey = CacheKey::get($bucket, $key);
        try {
            return $this->lock($cacheKey)
                ->block(1, fn() => $this->increment($cacheKey, $decaySeconds));
        } catch (LockTimeoutException) {
            return 0;
        }
    }

    private function lock(string $scope): \Illuminate\Contracts\Cache\Lock
    {
        return Cache::lock('rate_limit:' . hash('sha256', $scope), 10);
    }

    private function increment(string $cacheKey, int $decaySeconds): int
    {
        // Open the window on first sight; add() is a no-op afterwards.
        Cache::add($cacheKey, 0, $decaySeconds);
        return (int) Cache::increment($cacheKey);
    }
}
