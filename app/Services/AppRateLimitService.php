<?php

namespace App\Services;

use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;

/**
 * Fixed-window per-IP attempt counter used as a compensating control
 * on the passport endpoints (sendEmailVerify / register).
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
        // Open the window on first sight; add() is a no-op afterwards.
        Cache::add($key, 0, $decaySeconds);
        return Cache::increment($key) <= $maxAttempts;
    }
}
