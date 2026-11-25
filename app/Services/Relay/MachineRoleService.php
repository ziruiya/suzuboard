<?php

namespace App\Services\Relay;

use App\Models\RelayAuditLog;
use App\Models\RelayRule;
use App\Models\Server;
use App\Models\ServerMachine;
use Illuminate\Support\Facades\DB;

/**
 * Machine roles (owner ruling 2026-11-25): a machine is a landing machine
 * OR a relay machine, never both. `v2_server_machine.role` is the single
 * authority; this service is the only write path for role changes and for
 * the machine's public landing address.
 *
 * Role switches with existing stock are REFUSED with the cleanup path in
 * the message — never silently cascaded:
 *  - to relay:   the machine must host no nodes;
 *  - to landing: the machine must have no relay rules and its relay must
 *                already be disabled (drain/stop it first).
 */
class MachineRoleService
{
    /**
     * TCP reachability prober for the public-address precheck:
     * fn(string $host, int $port, float $timeoutSeconds): bool.
     * Injectable for tests; defaults to a real fsockopen probe.
     */
    private mixed $tcpProber;

    public function __construct(private RelayConfigService $config, mixed $tcpProber = null)
    {
        $this->tcpProber = $tcpProber ?? static function (string $host, int $port, float $timeout): bool {
            $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
            if ($fp === false) {
                return false;
            }
            fclose($fp);
            return true;
        };
    }

    public function setRole(ServerMachine $machine, string $role, string $actorType = 'admin', ?int $actorId = null): ServerMachine
    {
        if (!in_array($role, [ServerMachine::ROLE_LANDING, ServerMachine::ROLE_RELAY], true)) {
            throw new RelayValidationException('role must be landing or relay');
        }

        // R05: the stock checks and the role write must be atomic with
        // respect to node assignment. Both sides serialize on the
        // machine row: setRole re-reads the machine under lockForUpdate
        // and counts stock on the locked copy, and every assignment path
        // takes the same lock before writing v2_server.machine_id (see
        // assertAssignableLocked()). A stale in-memory model can never
        // smuggle a switch past a concurrent assignment — exactly one
        // side wins, the other is refused.
        return DB::transaction(function () use ($machine, $role, $actorType, $actorId) {
            $machine = ServerMachine::where('id', $machine->id)->lockForUpdate()->first() ?? $machine;
            if ($machine->role === $role) {
                return $machine;
            }

            if ($role === ServerMachine::ROLE_RELAY) {
                $nodes = Server::where('machine_id', $machine->id)->count();
                if ($nodes > 0) {
                    throw new RelayValidationException(
                        'cannot set role=relay: this machine still hosts ' . $nodes . ' node(s). '
                        . 'Unassign or delete its nodes in node management first — a machine is either a landing machine or a relay machine, never both.'
                    );
                }
            } else {
                $rules = RelayRule::where('machine_id', $machine->id)->count();
                if ($rules > 0) {
                    throw new RelayValidationException(
                        'cannot set role=landing: this machine still has ' . $rules . ' relay rule(s). '
                        . 'Delete them in relay management first — a machine is either a landing machine or a relay machine, never both.'
                    );
                }
                if ($machine->relay_enabled || $machine->relay_status !== ServerMachine::RELAY_STATUS_DISABLED) {
                    throw new RelayValidationException(
                        'cannot set role=landing: the relay on this machine is still active. Disable the relay first, then change the role.'
                    );
                }
            }

            $from = $machine->role;
            $machine->forceFill(['role' => $role])->save();
            RelayAuditLog::record($machine->id, null, $actorType, $actorId, 'machine.role', $from . ' -> ' . $role);
            return $machine->fresh();
        });
    }

    /**
     * R05 locked assignment guard: call INSIDE a transaction on every
     * write path that sets v2_server.machine_id. Takes the machine row
     * lock (serializing against setRole's locked stock check) and throws
     * when the machine is a relay machine. nodeAssignmentError() remains
     * the cheap unlocked pre-check for early, friendly failures; this is
     * the race-safe gate.
     */
    public static function assertAssignableLocked(?int $machineId): void
    {
        if (!$machineId) {
            return;
        }
        $machine = ServerMachine::where('id', $machineId)->lockForUpdate()->first();
        if ($machine && $machine->isRelayRole()) {
            throw new RelayValidationException(
                'machine "' . $machine->name . '" is a relay machine and cannot host nodes (machine roles are exclusive: landing or relay, never both)'
            );
        }
    }

    /**
     * Node-assignment guard used by every admin write path that can set
     * v2_server.machine_id. Returns an error message, or null when the
     * assignment is allowed.
     */
    public static function nodeAssignmentError(?int $machineId): ?string
    {
        if (!$machineId) {
            return null;
        }
        $machine = ServerMachine::find($machineId);
        if ($machine && $machine->isRelayRole()) {
            return 'machine "' . $machine->name . '" is a relay machine and cannot host nodes (machine roles are exclusive: landing or relay, never both)';
        }
        return null;
    }

    /**
     * Maintain the machine's authoritative public landing address. When it
     * changes, every relay machine holding NODE rules that target nodes on
     * this machine republishes, so the derived targets follow the address
     * automatically.
     */
    public function setPublicAddress(ServerMachine $machine, ?string $address, string $actorType = 'admin', ?int $actorId = null): ServerMachine
    {
        return $this->setPublicAddressWithReport($machine, $address, $actorType, $actorId)['machine'];
    }

    /**
     * R02 + R11: an address change is a fleet-wide retarget, so it is no
     * longer fire-and-forget:
     *
     *  - Precheck (R02): before anything is saved, the NEW address must
     *    answer on at least one sampled landing port of the nodes the
     *    follower relay machines target (TCP connect probe). A dead new
     *    address refuses the whole change — the old address stays in
     *    force everywhere.
     *  - Per-follower accounting (R11): each follower relay machine is
     *    published independently; the outcome is persisted per machine
     *    in the audit log (machine.public_address.follow, published vN /
     *    FAILED: reason) and returned in the report. A failed follower
     *    keeps its previously published configuration untouched
     *    (publish() is transactional) and is named in the result instead
     *    of silently leaving the fleet half-retargeted.
     *
     * @return array{machine: ServerMachine, followers: array<int, array{machine_id: int, status: string, version?: int, error?: string}>}
     */
    public function setPublicAddressWithReport(ServerMachine $machine, ?string $address, string $actorType = 'admin', ?int $actorId = null): array
    {
        $address = $address !== null ? trim($address) : null;
        if ($address === '') {
            $address = null;
        }
        if ($address !== null && !self::isValidPublicAddress($address)) {
            throw new RelayValidationException('public_address must be an IP address or a hostname');
        }
        if ($machine->public_address === $address) {
            return ['machine' => $machine, 'followers' => []];
        }

        $followerIds = RelayRule::where('target_type', RelayRule::TARGET_NODE)
            ->whereIn('target_node_id', Server::where('machine_id', $machine->id)->pluck('id'))
            ->distinct()
            ->pluck('machine_id')
            ->map(fn($id) => (int) $id)
            ->all();

        if ($address !== null && $followerIds) {
            $this->precheckReachable($machine, $address, $actorType, $actorId);
        }

        $machine->forceFill(['public_address' => $address])->save();
        RelayAuditLog::record($machine->id, null, $actorType, $actorId, 'machine.public_address', $address ?? '(cleared)');

        $followers = [];
        foreach ($followerIds as $relayMachineId) {
            $relayMachine = ServerMachine::find($relayMachineId);
            if (!$relayMachine) {
                $followers[] = ['machine_id' => $relayMachineId, 'status' => 'failed', 'error' => 'relay machine no longer exists'];
                RelayAuditLog::record($relayMachineId, null, $actorType, $actorId, 'machine.public_address.follow', 'FAILED: relay machine no longer exists (address change on machine ' . $machine->id . ')');
                continue;
            }
            try {
                $outbox = $this->config->publish($relayMachine, $actorType, $actorId);
                $followers[] = ['machine_id' => $relayMachineId, 'status' => 'published', 'version' => (int) $outbox->version];
                RelayAuditLog::record($relayMachineId, null, $actorType, $actorId, 'machine.public_address.follow', 'published v' . $outbox->version . ' (address change on machine ' . $machine->id . ')');
            } catch (\Throwable $e) {
                $followers[] = ['machine_id' => $relayMachineId, 'status' => 'failed', 'error' => $e->getMessage()];
                RelayAuditLog::record($relayMachineId, null, $actorType, $actorId, 'machine.public_address.follow', 'FAILED: ' . $e->getMessage() . ' (address change on machine ' . $machine->id . '; machine keeps its previous published config)');
            }
        }
        return ['machine' => $machine->fresh(), 'followers' => $followers];
    }

    /**
     * R02 precheck: sample the effective landing ports of this machine's
     * nodes that followers target, and require the new address to accept
     * a TCP connection on at least one of them. Refuses (and audits) the
     * change when no sampled port answers.
     */
    private function precheckReachable(ServerMachine $machine, string $address, string $actorType, ?int $actorId): void
    {
        // Ports are derived from the nodes directly (strict direct_port
        // semantics), not via nodeLandingEndpoint(): that helper reads
        // the machine's CURRENT address, which is exactly what is being
        // changed — and may still be empty on a first-time assignment.
        $ports = Server::where('machine_id', $machine->id)->get()
            ->map(fn(Server $node) => (int) ($node->direct_port !== null ? $node->direct_port : $node->server_port))
            ->filter(fn(int $port) => $port >= 1 && $port <= 65535)
            ->unique()
            ->take(5)
            ->values()
            ->all();
        if (!$ports) {
            return; // nothing derivable to probe; the publish-side BROKEN gate still applies
        }
        foreach ($ports as $port) {
            if (($this->tcpProber)($address, (int) $port, 2.0)) {
                return;
            }
        }
        RelayAuditLog::record($machine->id, null, $actorType, $actorId, 'machine.public_address.precheck_failed', $address . ' ports ' . implode(',', $ports));
        throw new RelayValidationException(
            'public_address precheck failed: none of the sampled landing ports (' . implode(', ', $ports)
            . ') accepts a TCP connection at ' . $address . '; the change is refused and the current address stays in force'
        );
    }

    public static function isValidPublicAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP) !== false
            || (bool) preg_match('/^(?=.{1,253}$)([a-zA-Z0-9-]+\.)*[a-zA-Z0-9-]+$/', $address);
    }
}
