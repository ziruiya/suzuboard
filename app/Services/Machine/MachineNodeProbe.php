<?php

namespace App\Services\Machine;

/**
 * Network edge for dedicated-VPS delivery verification. The production
 * implementation is a plain TCP reachability check against the machine's
 * public IP; tests bind a fake.
 */
interface MachineNodeProbe
{
    public function tcpReachable(string $host, int $port): bool;
}
