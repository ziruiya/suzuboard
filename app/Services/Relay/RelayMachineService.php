<?php

namespace App\Services\Relay;

use App\Models\RelayAuditLog;
use App\Models\RelayRule;
use App\Models\ServerMachine;

/**
 * SB-RELAY-01: relay-machine lifecycle on the panel side.
 * Enable publishes the current ruleset; disable walks the design's close
 * path — DRAINING (empty config published, existing sessions get the
 * rules' drain window) and only then DISABLED, finished by the agent ACK
 * or by the reconcile timeout. Nothing here ever deletes rules.
 */
class RelayMachineService
{
    public function __construct(private RelayConfigService $config)
    {
    }

    public function enable(ServerMachine $machine, string $actorType = 'admin', ?int $actorId = null): ServerMachine
    {
        if (!$machine->isRelayRole()) {
            throw new RelayValidationException(
                'machine "' . $machine->name . '" is a landing machine (role=landing) and cannot run the relay; set its role to relay first (it must host no nodes)'
            );
        }
        $machine->forceFill([
            'relay_enabled' => true,
            'relay_status' => ServerMachine::RELAY_STATUS_ENABLED,
            'relay_status_changed_at' => now(),
            'relay_last_error' => null,
        ])->save();
        RelayAuditLog::record($machine->id, null, $actorType, $actorId, 'machine.enable', $machine->name);
        $this->config->publish($machine, $actorType, $actorId);
        return $machine->fresh();
    }

    public function beginDrain(ServerMachine $machine, string $actorType = 'admin', ?int $actorId = null): ServerMachine
    {
        $machine->forceFill([
            'relay_status' => ServerMachine::RELAY_STATUS_DRAINING,
            'relay_status_changed_at' => now(),
        ])->save();
        RelayRule::where('machine_id', $machine->id)->where('enabled', true)
            ->update(['status' => RelayRule::STATUS_DRAINING]);
        RelayAuditLog::record($machine->id, null, $actorType, $actorId, 'machine.drain_begin', $machine->name);
        // Publishing while DRAINING yields the empty ruleset: listeners stop
        // taking new traffic, sessions drain on the agent side.
        $this->config->publish($machine, $actorType, $actorId);
        return $machine->fresh();
    }

    /** DRAINING -> DISABLED. Called by reconcile on ACK or drain timeout. */
    public function finishDisable(ServerMachine $machine, string $reason, string $actorType = 'system', ?int $actorId = null): ServerMachine
    {
        $machine->forceFill([
            'relay_enabled' => false,
            'relay_status' => ServerMachine::RELAY_STATUS_DISABLED,
            'relay_status_changed_at' => now(),
        ])->save();
        RelayRule::where('machine_id', $machine->id)
            ->whereIn('status', [RelayRule::STATUS_DRAINING, RelayRule::STATUS_APPLYING, RelayRule::STATUS_APPLIED])
            ->update(['status' => RelayRule::STATUS_DISABLED]);
        RelayAuditLog::record($machine->id, null, $actorType, $actorId, 'machine.disabled', $reason);
        return $machine->fresh();
    }

    public function setTier(ServerMachine $machine, string $tier, ?int $rulesLimit, ?int $tcpLimit, ?int $udpLimit, string $actorType = 'admin', ?int $actorId = null): ServerMachine
    {
        if (!isset(ServerMachine::RELAY_TIERS[$tier])) {
            throw new RelayValidationException('unknown relay tier: ' . $tier);
        }
        $machine->forceFill([
            'relay_tier' => $tier,
            'relay_rules_limit' => $rulesLimit,
            'relay_tcp_limit' => $tcpLimit,
            'relay_udp_limit' => $udpLimit,
        ])->save();
        RelayAuditLog::record($machine->id, null, $actorType, $actorId, 'machine.tier', $tier);
        return $machine->fresh();
    }
}
