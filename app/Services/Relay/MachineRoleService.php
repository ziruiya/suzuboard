<?php

namespace App\Services\Relay;

use App\Models\RelayAuditLog;
use App\Models\RelayRule;
use App\Models\Server;
use App\Models\ServerMachine;

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
    public function __construct(private RelayConfigService $config)
    {
    }

    public function setRole(ServerMachine $machine, string $role, string $actorType = 'admin', ?int $actorId = null): ServerMachine
    {
        if (!in_array($role, [ServerMachine::ROLE_LANDING, ServerMachine::ROLE_RELAY], true)) {
            throw new RelayValidationException('role must be landing or relay');
        }
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
        $address = $address !== null ? trim($address) : null;
        if ($address === '') {
            $address = null;
        }
        if ($address !== null && !self::isValidPublicAddress($address)) {
            throw new RelayValidationException('public_address must be an IP address or a hostname');
        }
        if ($machine->public_address === $address) {
            return $machine;
        }
        $machine->forceFill(['public_address' => $address])->save();
        RelayAuditLog::record($machine->id, null, $actorType, $actorId, 'machine.public_address', $address ?? '(cleared)');

        $relayMachineIds = RelayRule::where('target_type', RelayRule::TARGET_NODE)
            ->whereIn('target_node_id', Server::where('machine_id', $machine->id)->pluck('id'))
            ->distinct()
            ->pluck('machine_id');
        foreach ($relayMachineIds as $relayMachineId) {
            $relayMachine = ServerMachine::find($relayMachineId);
            if ($relayMachine) {
                $this->config->publish($relayMachine, $actorType, $actorId);
            }
        }
        return $machine->fresh();
    }

    public static function isValidPublicAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP) !== false
            || (bool) preg_match('/^(?=.{1,253}$)([a-zA-Z0-9-]+\.)*[a-zA-Z0-9-]+$/', $address);
    }
}
