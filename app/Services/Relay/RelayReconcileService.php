<?php

namespace App\Services\Relay;

use App\Models\RelayAddressChange;
use App\Models\RelayRevocation;
use App\Models\RelayRule;
use App\Models\Server;
use App\Models\ServerMachine;

/**
 * SB-RELAY-01 five-minute reconciliation:
 *  - DRAINING machines finish to DISABLED once the agent ACKs the empty
 *    config, or once the longest rule drain window has elapsed since the
 *    drain began (forced cleanup is recorded in the audit log — UDP has no
 *    lossless drain promise, design v0.2);
 *  - rules whose applied state is stale while a newer config is pending
 *    and the machine has not been seen are marked UNKNOWN (never silently
 *    treated as applied);
 *  - NODE targets are re-checked: a node that lost eligibility since the
 *    last publish turns its rule BROKEN and triggers a republish without
 *    it (buildDocument does the marking; publish excludes it).
 */
class RelayReconcileService
{
    public function __construct(
        private RelayConfigService $config,
        private RelayMachineService $machines,
    ) {
    }

    public function run(): array
    {
        $summary = ['drained' => 0, 'unknown' => 0, 'republished' => 0, 'revocation_pending' => 0];

        ServerMachine::where('relay_status', ServerMachine::RELAY_STATUS_DRAINING)->get()
            ->each(function (ServerMachine $machine) use (&$summary) {
                $ackedEmpty = (int) $machine->relay_applied_version >= (int) $machine->relay_config_version
                    && (int) $machine->relay_config_version > 0;
                $maxDrain = (int) (RelayRule::where('machine_id', $machine->id)->max('drain_timeout_sec') ?? 300);
                $elapsed = $machine->relay_status_changed_at
                    ? now()->timestamp - $machine->relay_status_changed_at : 0;
                if ($ackedEmpty) {
                    $this->machines->finishDisable($machine, 'agent acked drained config');
                    $summary['drained']++;
                } elseif ($elapsed > $maxDrain) {
                    $this->machines->finishDisable($machine, 'drain timeout ' . $maxDrain . 's exceeded, forced cleanup recorded');
                    $summary['drained']++;
                }
            });

        ServerMachine::where('relay_enabled', true)
            ->where('relay_status', ServerMachine::RELAY_STATUS_ENABLED)
            ->get()
            ->each(function (ServerMachine $machine) use (&$summary) {
                // Stale applies: config moved on, agent silent for >15 min.
                if ((int) $machine->relay_applied_version < (int) $machine->relay_config_version
                    && $machine->last_seen_at !== null
                    && now()->timestamp - (int) $machine->last_seen_at > 900) {
                    $n = RelayRule::where('machine_id', $machine->id)
                        ->where('status', RelayRule::STATUS_APPLYING)
                        ->update(['status' => RelayRule::STATUS_UNKNOWN]);
                    $summary['unknown'] += $n;
                }

                // Contract-hash drift on NODE targets (R08): the hash
                // covers the derived endpoint AND the eligibility-derived
                // state (rollout stage, stock registration, deliverable
                // verdict). It must be compared BEFORE buildDocument(),
                // whose snapshot refresh would otherwise absorb the drift
                // silently without a republish.
                $hashDrift = false;
                RelayRule::where('machine_id', $machine->id)
                    ->where('enabled', true)
                    ->where('target_type', RelayRule::TARGET_NODE)
                    ->get()
                    ->each(function (RelayRule $rule) use (&$hashDrift) {
                        $node = $rule->target_node_id ? Server::find($rule->target_node_id) : null;
                        if ($node && RelayRuleService::nodeContractHash($node) !== $rule->target_contract_hash) {
                            $hashDrift = true;
                        }
                    });

                // Eligibility drift on NODE targets -> republish (marks BROKEN).
                $before = RelayRule::where('machine_id', $machine->id)
                    ->where('status', RelayRule::STATUS_BROKEN)->count();
                $document = $this->config->buildDocument($machine);
                $after = RelayRule::where('machine_id', $machine->id)
                    ->where('status', RelayRule::STATUS_BROKEN)->count();
                if ($after > $before || $hashDrift) {
                    $this->config->publish($machine);
                    $summary['republished']++;
                }
            });

        // R03 + B01: revocations the fleet has published but no agent
        // has confirmed yet, counted from the obligation table (the
        // rule-row marker misses deleted rules by construction).
        // Reported every run so an offline agent leaves a standing,
        // visible count instead of a silent assumption.
        $summary['revocation_pending'] = RelayRevocation::whereNull('confirmed_at')->count();

        // B03: open address changes get their failed followers retried
        // and their convergence re-checked every run, so a follower
        // that failed mid-change converges without an operator having
        // to notice and click anything.
        $summary['address_changes_resumed'] = 0;
        $summary['address_changes_completed'] = 0;
        $roles = app(MachineRoleService::class);
        RelayAddressChange::whereIn('status', [
            RelayAddressChange::STATUS_PARTIAL,
            RelayAddressChange::STATUS_AWAITING_ACK,
            RelayAddressChange::STATUS_IN_PROGRESS,
        ])->orderBy('id')->limit(20)->get()
            ->each(function (RelayAddressChange $change) use (&$summary, $roles) {
                $after = $roles->resumeAddressChange($change, 'system');
                $summary['address_changes_resumed']++;
                if ($after->status === RelayAddressChange::STATUS_COMPLETE) {
                    $summary['address_changes_completed']++;
                }
            });

        return $summary;
    }
}
