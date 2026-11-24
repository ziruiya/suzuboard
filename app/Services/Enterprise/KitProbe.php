<?php

namespace App\Services\Enterprise;

use App\Models\EnterpriseInventoryKit;
use App\Models\User;

/**
 * Network-edge probes used by kit verification. The production
 * implementation drives the bundled sing-box engine with a dedicated
 * enterprise-probe identity; tests bind a fake. Content checks (names,
 * keys, paths) are done by the health service itself from panel data —
 * implementations of this interface only answer reachability questions.
 */
interface KitProbe
{
    /** TCP reachability of the entry endpoint for one kit node. */
    public function tcpReachable(string $host, int $port): bool;

    /**
     * Full client-path probe through one kit node using the probe identity:
     * performs an HTTP generate_204 and reports the exit IP.
     *
     * @return array{ok: bool, exit_ip: ?string, status: ?int, error: ?string}
     */
    public function probeThroughNode(EnterpriseInventoryKit $kit, int $serverId, User $probeUser): array;
}
