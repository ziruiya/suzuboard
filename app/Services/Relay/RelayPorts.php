<?php

namespace App\Services\Relay;

/**
 * SB-RELAY-01 port pool, fixed by the 2026-11-25 preflight inventory:
 * 20000–28999 measured with zero listeners on every reachable machine;
 * 29000–29999 is reserved headroom and stays closed in this batch.
 */
final class RelayPorts
{
    public const POOL_MIN = 20000;
    public const POOL_MAX = 28999;

    public static function inPool(int $port): bool
    {
        return $port >= self::POOL_MIN && $port <= self::POOL_MAX;
    }
}
