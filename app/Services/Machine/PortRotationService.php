<?php

namespace App\Services\Machine;

use App\Models\EnterpriseInventoryKit;
use App\Models\EnterpriseInventoryNode;
use App\Models\EnterpriseKitContractSnapshot;
use App\Models\MachineFulfillment;
use App\Models\PortRotationLog;
use App\Models\RelayPortClaim;
use App\Models\RelayRule;
use App\Models\Server;
use App\Models\User;
use App\Services\Relay\RelayPorts;
use App\Services\Relay\RelayRuleService;
use Illuminate\Support\Facades\DB;

/**
 * Business plans batch 3 / D08: the 「更换端口」 button shared by both
 * business tiers. One click rotates BOTH the entry port and the landing
 * port of every dedicated line the customer holds; credentials, SNI,
 * hosts and the subscription address are frozen (SB-Endpoint contract
 * discipline). Cooldown: one rotation per user per 2 hours.
 *
 * Provider abstraction: a node whose entry is an in-panel relay rule is
 * rotated through the relay provider (re-claim a pool port on the relay
 * machine + move the node landing port + republish). A dedicated-VPS
 * node without a relay rule is direct (entry == landing) and rotates its
 * own port. Inventory-kit nodes still entering through the interim
 * third-party entry have NO automated rotation — the provider refuses
 * honestly instead of faking one; per D08 both tiers only open for sale
 * once lines live on the in-panel relay, so no sold assignment can be
 * in that state.
 */
class PortRotationService
{
    public const COOLDOWN_SECONDS = 7200; // D08: once per 2 hours

    public function __construct(private RelayRuleService $relayRules)
    {
    }

    /** Seconds of cooldown left for this user (0 = may rotate now). */
    public function cooldownRemaining(User $user): int
    {
        return $this->remainingFrom(PortRotationLog::where('user_id', $user->id)->latest('created_at')->first());
    }

    private function remainingFrom(?PortRotationLog $last): int
    {
        if (!$last || !$last->created_at) {
            return 0;
        }
        $elapsed = $last->created_at->diffInSeconds(now(), true);
        return max(0, self::COOLDOWN_SECONDS - (int) $elapsed);
    }

    /**
     * What the member center needs: does this user hold a business tier,
     * which one, and may they rotate right now?
     */
    public function statusForUser(User $user): array
    {
        $kit = $user->enterprise_kit_id
            ? EnterpriseInventoryKit::find($user->enterprise_kit_id)
            : null;
        $hasKit = $kit && in_array($kit->status, [
            EnterpriseInventoryKit::STATUS_ASSIGNED,
            EnterpriseInventoryKit::STATUS_ASSIGNED_LEGACY,
        ], true);
        $fulfillment = $user->machine_fulfillment_id
            ? MachineFulfillment::find($user->machine_fulfillment_id)
            : null;
        $hasMachine = $fulfillment && $fulfillment->status === MachineFulfillment::STATUS_DELIVERED;

        return [
            'has_business' => $hasKit || $hasMachine,
            'tier' => $hasMachine ? 'machine' : ($hasKit ? 'port' : null),
            'can_rotate' => ($hasKit || $hasMachine) && $this->cooldownRemaining($user) === 0,
            'cooldown_seconds' => $this->cooldownRemaining($user),
            'cooldown_hours' => 2,
        ];
    }

    /**
     * Rotate every dedicated line of the user's active business
     * assignment(s). Returns a per-node summary.
     *
     * @return array<int, array{server_id: int, name: ?string, old_port: int, new_port: int, provider: string}>
     */
    public function rotateForUser(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            return $this->rotateLockedUser($lockedUser);
        }, 3);
    }

    private function rotateLockedUser(User $user): array
    {
        $last = PortRotationLog::where('user_id', $user->id)->latest('created_at')->lockForUpdate()->first();
        $remaining = $this->remainingFrom($last);
        if ($remaining > 0) {
            throw new PortRotationCooldownException($remaining);
        }

        $targets = []; // [assignmentType, assignmentId, Server[]]
        if ($user->enterprise_kit_id) {
            $kit = EnterpriseInventoryKit::with('nodes.server')->find($user->enterprise_kit_id);
            if ($kit && in_array($kit->status, [EnterpriseInventoryKit::STATUS_ASSIGNED, EnterpriseInventoryKit::STATUS_ASSIGNED_LEGACY], true)) {
                $servers = $kit->nodes->map(fn($n) => $n->server)->filter()->values()->all();
                if ($servers) {
                    $targets[] = [PortRotationLog::TYPE_KIT, (int) $kit->id, $servers];
                }
            }
        }
        if ($user->machine_fulfillment_id) {
            $fulfillment = MachineFulfillment::find($user->machine_fulfillment_id);
            if ($fulfillment && $fulfillment->status === MachineFulfillment::STATUS_DELIVERED) {
                $servers = Server::whereIn('id', $fulfillment->nodeIds())->get()->all();
                if ($servers) {
                    $targets[] = [PortRotationLog::TYPE_MACHINE, (int) $fulfillment->id, $servers];
                }
            }
        }
        if (!$targets) {
            throw new PortRotationException('No active business assignment to rotate');
        }

        $results = [];
        foreach ($targets as [$type, $assignmentId, $servers]) {
            $summary = [];
            foreach ($servers as $server) {
                $summary[] = $this->rotateNode($server);
            }
            if ($type === PortRotationLog::TYPE_KIT) {
                $this->updateKitContract($assignmentId, $summary);
            }
            PortRotationLog::create([
                'user_id' => $user->id,
                'assignment_type' => $type,
                'assignment_id' => $assignmentId,
                'detail' => json_encode($summary),
                'created_at' => now(),
            ]);
            $results = array_merge($results, $summary);
        }
        return $results;
    }

    private function updateKitContract(int $kitId, array $summary): void
    {
        $kit = EnterpriseInventoryKit::whereKey($kitId)->lockForUpdate()->firstOrFail();
        $ports = collect($summary)->keyBy('server_id');
        EnterpriseInventoryNode::where('kit_id', $kitId)->get()->each(function (EnterpriseInventoryNode $node) use ($ports) {
            if ($result = $ports->get($node->server_id)) {
                $node->update(['port' => $result['entry_port'], 'landing_port' => $result['new_port']]);
            }
        });

        $version = $kit->current_contract_version + 1;
        $snapshots = EnterpriseKitContractSnapshot::where('kit_id', $kitId)
            ->where('contract_version', $kit->current_contract_version)->get();
        foreach ($snapshots as $snapshot) {
            $copy = $snapshot->replicate();
            if ($result = $ports->get($snapshot->server_id)) {
                $copy->entry_port = $result['entry_port'];
                $copy->target_port = $result['new_port'];
            }
            $copy->contract_version = $version;
            $copy->revision = $version;
            $copy->captured_at = now();
            $copy->save();
        }
        if ($snapshots->isNotEmpty()) {
            $kit->update(['current_contract_version' => $version]);
        }
    }

    /** Rotate one dedicated node; only its ports may change. */
    private function rotateNode(Server $server): array
    {
        // Front-proxied nodes (direct_port set) are shared-fleet nodes,
        // never rotation customers: rotating server_port would move the
        // derived landing endpoint out from under the front proxy.
        if ($server->direct_port) {
            throw new PortRotationException('该节点为前置型节点（设有落地直达端口），不支持自动更换端口');
        }
        $oldPort = (int) $server->server_port;
        $rule = RelayRule::where('target_type', RelayRule::TARGET_NODE)
            ->where('target_node_id', $server->id)
            ->where('enabled', true)
            ->first();

        if ($rule) {
            // Relay-backed: re-claim the entry port on the relay machine
            // and move the landing port; RelayRuleService republishes.
            $machine = $rule->machine;
            $newEntry = $this->allocatePoolPort(function (int $port) use ($machine, $rule) {
                return $port !== (int) $rule->listen_port && !RelayPortClaim::where('machine_id', $machine->id)
                    ->where('listen_port', $port)
                    ->where('rule_id', '!=', $rule->id)
                    ->exists();
            });
            $newLanding = $this->allocatePoolPort(function (int $port) use ($server) {
                return $port !== (int) $server->server_port && !Server::where('machine_id', $server->machine_id)
                    ->where('server_port', $port)
                    ->where('id', '!=', $server->id)
                    ->exists();
            });
            // Order matters (GPT review R01): the node row must carry the
            // NEW landing port BEFORE the rule update republishes, because
            // the published document derives its target_port from the
            // node's server_port at publish time. Publishing first would
            // pin the relay to the old landing port while the node agent
            // moves to the new one. Both writes sit in rotateForUser()'s
            // transaction, so a failed rule update rolls the node back too.
            $server->server_port = $newLanding;
            $server->port = (string) $newEntry; // client-facing entry follows the relay listener
            $server->save();
            $this->relayRules->update($rule, ['listen_port' => $newEntry], 'user', null);
            return [
                'server_id' => (int) $server->id,
                'name' => $server->name,
                'old_port' => $oldPort,
                'new_port' => $newLanding,
                'entry_port' => $newEntry,
                'provider' => 'relay',
            ];
        }

        if ($server->machine_id && $this->isMachineFulfillmentNode($server)) {
            // Direct dedicated-VPS node: entry port == landing port.
            $newPort = $this->allocatePoolPort(function (int $port) use ($server) {
                return $port !== (int) $server->server_port && !Server::where('machine_id', $server->machine_id)
                    ->where('server_port', $port)
                    ->where('id', '!=', $server->id)
                    ->exists();
            });
            $server->server_port = $newPort;
            $server->port = (string) $newPort;
            $server->save();
            return [
                'server_id' => (int) $server->id,
                'name' => $server->name,
                'old_port' => $oldPort,
                'new_port' => $newPort,
                'entry_port' => $newPort,
                'provider' => 'direct',
            ];
        }

        // Inventory-kit node on the interim third-party entry: no honest
        // automation exists yet (the entry port lives outside the panel).
        throw new PortRotationException('专属线路尚未迁入站内中转，暂时无法自动更换端口');
    }

    private function isMachineFulfillmentNode(Server $server): bool
    {
        return MachineFulfillment::where('reality_server_id', $server->id)
            ->orWhere('ss1_server_id', $server->id)
            ->orWhere('sw1_server_id', $server->id)
            ->exists();
    }

    /** First free port from the relay pool satisfying $free. */
    private function allocatePoolPort(callable $free): int
    {
        for ($port = RelayPorts::POOL_MIN; $port <= RelayPorts::POOL_MAX; $port++) {
            if ($free($port)) {
                return $port;
            }
        }
        throw new PortRotationException('No free port left in the rotation pool');
    }
}
