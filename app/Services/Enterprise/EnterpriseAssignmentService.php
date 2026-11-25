<?php

namespace App\Services\Enterprise;

use App\Jobs\EnterpriseDeliveryVerifyJob;
use App\Models\EnterpriseInventoryAlert;
use App\Models\EnterpriseInventoryKit;
use App\Models\EnterpriseInventoryLog;
use App\Models\EnterpriseInventoryNode;
use App\Models\EnterpriseKitContractSnapshot;
use App\Models\EnterpriseOrderIntent;
use App\Models\EnterprisePurpose;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Assignment of stock kits to paying enterprise customers.
 *
 * Hard constraint 1 (audit V02): OrderService::open()/buyByPeriod() writes
 * the plan's template group (group 5) into user.group_id. Enterprise
 * assignment therefore runs INSIDE open()'s transaction, after the period
 * purchase logic, and overwrites group_id with the kit's own group — the
 * user must never end up parked in the template group.
 */
class EnterpriseAssignmentService
{
    public function __construct(
        private EnterpriseInventoryService $inventory,
        private EnterpriseGroupMembershipService $membership,
        private EnterpriseReplenishmentService $replenishment,
    ) {
    }

    /**
     * Order-creation hook: create the intent and (when possible) hold one
     * READY kit for 30 minutes. HELD never changes the user's group.
     */
    public function holdForOrder(Order $order, EnterprisePurpose $purpose, User $user): EnterpriseOrderIntent
    {
        return DB::transaction(function () use ($order, $purpose, $user) {
            $intent = EnterpriseOrderIntent::create([
                'user_id' => $user->id,
                'order_id' => $order->id,
                'purpose_id' => $purpose->id,
                'status' => EnterpriseOrderIntent::STATUS_HELD,
                'hold_expires_at' => now()->addMinutes(30),
            ]);

            // Renewal of an existing assignment: the intent binds to the
            // user's current kit, no stock is held.
            if ($user->enterprise_kit_id) {
                $kit = EnterpriseInventoryKit::find($user->enterprise_kit_id);
                if ($kit && in_array($kit->status, [
                    EnterpriseInventoryKit::STATUS_ASSIGNED,
                    EnterpriseInventoryKit::STATUS_ASSIGNED_LEGACY,
                    EnterpriseInventoryKit::STATUS_GRACE_HELD,
                ], true)) {
                    $intent->kit_id = $kit->id;
                    $intent->save();
                    return $intent;
                }
            }

            $kit = $this->pickReadyKit($purpose, lock: true);
            if ($kit) {
                $this->inventory->transition($kit, EnterpriseInventoryKit::STATUS_HELD, 'kit.held', "order {$order->trade_no}");
                $kit->held_order_id = $order->id;
                $kit->hold_expires_at = now()->addMinutes(30);
                $kit->save();
                $intent->kit_id = $kit->id;
                $intent->save();
                $this->inventory->refreshLevel($purpose->id);
            }
            return $intent;
        });
    }

    /** Release a hold (order cancelled or hold timed out). */
    public function releaseHold(EnterpriseOrderIntent $intent, string $reason): void
    {
        DB::transaction(function () use ($intent, $reason) {
            $intent = EnterpriseOrderIntent::lockForUpdate()->find($intent->id);
            if (!$intent || $intent->status !== EnterpriseOrderIntent::STATUS_HELD) {
                return;
            }
            if ($intent->kit_id) {
                $kit = EnterpriseInventoryKit::lockForUpdate()->find($intent->kit_id);
                if ($kit && $kit->status === EnterpriseInventoryKit::STATUS_HELD
                    && (int) $kit->held_order_id === (int) $intent->order_id) {
                    $kit->held_order_id = null;
                    $kit->hold_expires_at = null;
                    $kit->save();
                    $this->inventory->transition($kit, EnterpriseInventoryKit::STATUS_READY, 'kit.hold_released', $reason);
                    $this->inventory->refreshLevel($kit->purpose_id);
                }
            }
            $intent->status = EnterpriseOrderIntent::STATUS_FAILED;
            $intent->note = $reason;
            $intent->save();
        });
    }

    /**
     * The paid()-chain interception. MUST be called inside open()'s
     * transaction with open()'s own locked Order/User instances.
     *
     * @return array{intent: EnterpriseOrderIntent, delivery_kit_id: ?int, purpose_id: ?int}|null
     */
    public function assignWithinOpen(Order $order, User $user, Plan $plan): ?array
    {
        $intent = EnterpriseOrderIntent::where('order_id', $order->id)->lockForUpdate()->first();
        if (!$intent) {
            return null;
        }
        $purpose = EnterprisePurpose::find($intent->purpose_id);
        if (!$purpose) {
            return null;
        }

        // Renewal / repurchase by a user who already owns a kit: restore or
        // keep the SAME kit — group, entry and UUID never change (E07/E12).
        if ($user->enterprise_kit_id) {
            $kit = EnterpriseInventoryKit::lockForUpdate()->find($user->enterprise_kit_id);
            if ($kit && in_array($kit->status, [
                EnterpriseInventoryKit::STATUS_ASSIGNED,
                EnterpriseInventoryKit::STATUS_ASSIGNED_LEGACY,
                EnterpriseInventoryKit::STATUS_GRACE_HELD,
            ], true)) {
                if ($kit->status !== EnterpriseInventoryKit::STATUS_ASSIGNED) {
                    $this->inventory->transition($kit, EnterpriseInventoryKit::STATUS_ASSIGNED, 'kit.renewed', "order {$order->trade_no}");
                }
                $kit->grace_until = null;
                $kit->current_order_id = $order->id;
                $kit->save();
                $user->group_id = $kit->stock_group_id;
                $order->enterprise_kit_id = $kit->id;
                $intent->kit_id = $kit->id;
                $intent->status = EnterpriseOrderIntent::STATUS_DELIVERED;
                $intent->save();
                return ['intent' => $intent, 'delivery_kit_id' => null, 'purpose_id' => $purpose->id];
            }
            // The linked kit is dead (quarantined/retired): fall through to a
            // fresh claim and drop the stale link.
            $user->enterprise_kit_id = null;
        }

        $kit = null;
        if ($intent->kit_id) {
            $candidate = EnterpriseInventoryKit::lockForUpdate()->find($intent->kit_id);
            // SB-ER-007: the reserved candidate must pass the same
            // freshness gate as a fresh pick, inside this lock — a kit
            // that lost its health qualification between hold and
            // payment is not delivered; the flow falls through to a
            // fresh pick or to the waiting queue.
            if ($candidate && $candidate->status === EnterpriseInventoryKit::STATUS_HELD
                && (int) $candidate->held_order_id === (int) $order->id
                && $candidate->freshnessOk()) {
                $kit = $candidate;
            } elseif ($candidate && $candidate->status === EnterpriseInventoryKit::STATUS_READY
                && $candidate->assignmentFresh()) {
                $kit = $candidate;
            }
        }
        if (!$kit) {
            $kit = $this->pickReadyKit($purpose, lock: true);
        }

        if (!$kit) {
            $intent->status = EnterpriseOrderIntent::STATUS_PAID_WAITING_INVENTORY;
            $intent->waiting_since = now();
            $intent->kit_id = null;
            $intent->save();
            EnterpriseInventoryAlert::raise($purpose->id, null, EnterpriseInventoryAlert::TYPE_STOCK_LOW, "Order {$order->trade_no} paid but no READY kit for {$purpose->code}; queued (D01: entry stays open, D02: no refund)");
            EnterpriseInventoryLog::record(null, 'intent.waiting', "order {$order->trade_no} purpose {$purpose->code}");
            return ['intent' => $intent, 'delivery_kit_id' => null, 'purpose_id' => $purpose->id];
        }

        $this->claimKit($kit, $user, $order, $intent);
        return ['intent' => $intent, 'delivery_kit_id' => $kit->id, 'purpose_id' => $purpose->id];
    }

    /** Post-commit follow-ups for open(): delivery verification + replenishment (E03). */
    public function afterOpenCommitted(?array $result): void
    {
        if (!$result) {
            return;
        }
        if (!empty($result['delivery_kit_id']) && !empty($result['intent'])) {
            EnterpriseDeliveryVerifyJob::dispatch($result['delivery_kit_id'], $result['intent']->id);
        }
        if (!empty($result['purpose_id'])) {
            $purpose = EnterprisePurpose::find($result['purpose_id']);
            if ($purpose) {
                $this->replenishment->replenishIfNeeded($purpose);
            }
        }
    }

    /**
     * Drain one waiting intent when stock exists (FIFO handled by caller).
     * Returns true when a kit was assigned.
     */
    public function assignWaitingIntent(EnterpriseOrderIntent $intent): bool
    {
        $assignedKitId = DB::transaction(function () use ($intent) {
            $intent = EnterpriseOrderIntent::lockForUpdate()->find($intent->id);
            if (!$intent || $intent->status !== EnterpriseOrderIntent::STATUS_PAID_WAITING_INVENTORY) {
                return null;
            }
            $user = User::lockForUpdate()->find($intent->user_id);
            $order = Order::find($intent->order_id);
            if (!$user || !$order) {
                // SB-ER-006 (residual after SB-MONEY-008): a queue head
                // whose user or order row is gone can never be
                // delivered, and returning without a state change left
                // it at the head of the FIFO forever — the drain loop
                // stops on an unchanged head. Close it for manual
                // handling exactly like the lapsed-subscription case.
                $intent->status = EnterpriseOrderIntent::STATUS_FAILED;
                $intent->note = 'linked user or order missing; moved to manual handling (D02: no refund)';
                $intent->save();
                EnterpriseInventoryAlert::raise(
                    $intent->purpose_id,
                    null,
                    EnterpriseInventoryAlert::TYPE_WAITING_TOO_LONG,
                    "Order intent #{$intent->id} references a missing user/order; intent closed for manual handling (D02: no refund)"
                );
                EnterpriseInventoryLog::record(null, 'intent.orphaned_closed', "intent {$intent->id}");
                return null;
            }
            if ($user->enterprise_kit_id) {
                $intent->status = EnterpriseOrderIntent::STATUS_DELIVERED;
                $intent->save();
                return null;
            }
            if ($user->expired_at !== null && $user->expired_at < time()) {
                // SB-MONEY-008: the subscription this paid order bought
                // has lapsed while queued, so the intent can never be
                // delivered from the queue. Leaving it at the head of
                // the FIFO blocked every later intent for the same
                // purpose indefinitely. Move it to an explicit
                // manual-handling state instead (D02: no refund).
                $intent->status = EnterpriseOrderIntent::STATUS_FAILED;
                $intent->note = 'subscription lapsed while waiting for inventory; moved to manual handling (D02: no refund)';
                $intent->save();
                EnterpriseInventoryAlert::raise(
                    $intent->purpose_id,
                    null,
                    EnterpriseInventoryAlert::TYPE_WAITING_TOO_LONG,
                    "Order {$order->trade_no} lapsed while waiting for a kit (purpose {$intent->purpose_id}); intent closed for manual handling (D02: no refund)"
                );
                EnterpriseInventoryLog::record(null, 'intent.lapsed_closed', "order {$order->trade_no}");
                return null;
            }
            $purpose = EnterprisePurpose::find($intent->purpose_id);
            $kit = $purpose ? $this->pickReadyKit($purpose, lock: true) : null;
            if (!$kit) {
                return null;
            }
            $this->claimKit($kit, $user, $order, $intent);
            $user->save();
            $order->save();
            return $kit->id;
        });

        if ($assignedKitId) {
            EnterpriseDeliveryVerifyJob::dispatch($assignedKitId, $intent->id);
            // SB-ER-003 (residual after SB-MONEY-007): the kit just
            // became usable, so a DELIVERED dedicated machine the
            // customer still holds must start its grace window now —
            // the same switch OrderService::open() performs post-commit
            // for instantly-assigned kits. The drain path previously
            // skipped it, leaving the machine DELIVERED forever while
            // the customer's group had already moved to the kit.
            $customer = User::find($intent->user_id);
            if ($customer) {
                app(\App\Services\Machine\MachineFulfillmentService::class)
                    ->supersedeActiveMachine($customer, 'customer switched to the dedicated-port tier (waiting-queue assignment)');
            }
            return true;
        }
        return false;
    }

    /**
     * Manual revocation (E08): detach the customer from the kit, strip the
     * kit group's VIP access, hide the nodes and quarantine the kit.
     */
    public function revokeAssignment(EnterpriseInventoryKit $kit, ?int $adminId = null): void
    {
        DB::transaction(function () use ($kit, $adminId) {
            $kit = EnterpriseInventoryKit::lockForUpdate()->find($kit->id);
            if (!$kit || !in_array($kit->status, [
                EnterpriseInventoryKit::STATUS_ASSIGNED,
                EnterpriseInventoryKit::STATUS_ASSIGNED_LEGACY,
                EnterpriseInventoryKit::STATUS_GRACE_HELD,
            ], true)) {
                throw new \RuntimeException('Kit is not in an assigned state');
            }
            $this->membership->removeGroupFromVipServers($kit->stock_group_id);
            Server::whereIn('id', $kit->nodes()->pluck('server_id')->filter()->all())
                ->update(['show' => false]);
            $user = $kit->current_user_id ? User::lockForUpdate()->find($kit->current_user_id) : null;
            if ($user && (int) $user->enterprise_kit_id === (int) $kit->id) {
                $user->enterprise_kit_id = null;
                $plan = $user->plan_id ? Plan::find($user->plan_id) : null;
                $user->group_id = $plan?->group_id;
                $user->save();
            }
            $kit->current_user_id = null;
            $kit->current_order_id = null;
            $kit->grace_until = null;
            $kit->save();
            $this->inventory->transition($kit, EnterpriseInventoryKit::STATUS_QUARANTINED, 'kit.revoked', 'manual revocation by admin', EnterpriseInventoryLog::ACTOR_ADMIN, $adminId);
        });
    }

    /**
     * Register a pre-existing manually provisioned customer in place
     * (E14): the group and nodes already exist and the subscription must
     * not change — we only start tracking them as a kit.
     */
    public function registerLegacy(User $user, EnterprisePurpose $purpose, int $groupId, array $serverIds): EnterpriseInventoryKit
    {
        if ((int) $user->group_id !== $groupId) {
            throw new \RuntimeException('User does not belong to the legacy group');
        }
        $servers = Server::whereIn('id', $serverIds)->orderBy('sort')->get();
        if ($servers->count() !== 2) {
            throw new \RuntimeException('A legacy kit needs exactly two existing nodes');
        }
        foreach ($servers as $server) {
            $ids = array_map('strval', $server->group_ids ?? []);
            if ($ids !== [(string) $groupId]) {
                throw new \RuntimeException("Node {$server->id} is not isolated to group {$groupId} (AUD-015)");
            }
        }

        return DB::transaction(function () use ($user, $purpose, $groupId, $servers) {
            $kit = EnterpriseInventoryKit::create([
                'purpose_id' => $purpose->id,
                'stock_group_id' => $groupId,
                'sequence' => $this->inventory->nextSequence($purpose->id),
                'status' => EnterpriseInventoryKit::STATUS_ASSIGNED_LEGACY,
                'current_user_id' => $user->id,
                'assigned_at' => now(),
            ]);
            $roles = [EnterpriseInventoryNode::ROLE_PRIMARY, EnterpriseInventoryNode::ROLE_BACKUP];
            foreach ($servers as $i => $server) {
                EnterpriseInventoryNode::create([
                    'kit_id' => $kit->id,
                    'server_id' => $server->id,
                    'node_role' => $roles[$i],
                    'port' => (int) $server->port,
                    'landing_port' => $server->server_port,
                    'dns_alias' => $server->host,
                ]);
                $settings = $server->protocol_settings ?? [];
                EnterpriseKitContractSnapshot::create([
                    'kit_id' => $kit->id,
                    'server_id' => $server->id,
                    'contract_version' => 1,
                    'entry_address' => $server->host,
                    'entry_port' => (int) $server->port,
                    'protocol' => $purpose->protocol,
                    'uuid' => $user->uuid,
                    'short_id' => $settings['reality_settings']['short_id'] ?? null,
                    'sni' => $settings['reality_settings']['server_name'] ?? ($settings['tls_settings']['server_name'] ?? null),
                    'public_key' => $settings['reality_settings']['public_key'] ?? null,
                    'ws_path' => $settings['network_settings']['path'] ?? null,
                    'service_name' => $settings['network_settings']['serviceName'] ?? null,
                    'target_ip' => $server->host,
                    'target_port' => (int) $server->server_port,
                    'dns_alias' => $server->host,
                    'revision' => 1,
                    'captured_at' => now(),
                ]);
            }
            $kit->current_contract_version = 1;
            $kit->save();
            $user->enterprise_kit_id = $kit->id;
            $user->save();
            // Guarantee the VIP-access invariant for the legacy group too.
            $this->membership->addGroupToVipServers($groupId);
            EnterpriseInventoryLog::record($kit->id, 'kit.legacy_registered', "user {$user->id} group {$groupId}");
            return $kit;
        });
    }

    /** The shared claim sequence used by open() and by the waiting-queue drain. */
    private function claimKit(EnterpriseInventoryKit $kit, User $user, Order $order, EnterpriseOrderIntent $intent): void
    {
        if ($kit->status === EnterpriseInventoryKit::STATUS_READY) {
            $this->inventory->transition($kit, EnterpriseInventoryKit::STATUS_HELD, 'kit.held_paid', "order {$order->trade_no}");
            $kit->held_order_id = $order->id;
        }
        $this->inventory->transition($kit, EnterpriseInventoryKit::STATUS_RESERVED, 'kit.reserved', "order {$order->trade_no}");
        $kit->current_user_id = $user->id;
        $kit->current_order_id = $order->id;
        $kit->held_order_id = null;
        $kit->hold_expires_at = null;
        $kit->save();

        $user->group_id = $kit->stock_group_id;
        $user->enterprise_kit_id = $kit->id;
        $order->enterprise_kit_id = $kit->id;

        // Rename group + nodes from [STOCK] to [ENT] <customer>.
        $label = $this->customerLabel($user);
        $purpose = $kit->purpose;
        ServerGroup::whereKey($kit->stock_group_id)->update([
            'name' => sprintf('[ENT] %s %s', $label, $purpose?->code ?? ''),
        ]);
        foreach ($kit->nodes as $invNode) {
            if (!$invNode->server_id) {
                continue;
            }
            Server::whereKey($invNode->server_id)->update([
                'name' => sprintf('[ENT] %s %s %s', $label, $purpose?->name ?? '', $invNode->node_role === EnterpriseInventoryNode::ROLE_PRIMARY ? '主线' : '备线'),
                'show' => true,
            ]);
        }

        // Hard constraint 2: VIP access via the row-locked merge service.
        $this->membership->addGroupToVipServers($kit->stock_group_id);

        // Contract v2 binds the per-user credential (uuid) to the snapshot.
        $this->captureAssignmentSnapshot($kit, $user);
        $kit->current_contract_version = 2;
        $kit->assigned_at = now();
        $kit->save();
        $this->inventory->transition($kit, EnterpriseInventoryKit::STATUS_ASSIGNED, 'kit.assigned', "user {$user->id} order {$order->trade_no}");

        $intent->kit_id = $kit->id;
        $intent->status = EnterpriseOrderIntent::STATUS_RESERVED;
        $intent->save();
    }

    private function captureAssignmentSnapshot(EnterpriseInventoryKit $kit, User $user): void
    {
        $v1 = EnterpriseKitContractSnapshot::where('kit_id', $kit->id)
            ->where('contract_version', 1)
            ->get();
        foreach ($v1 as $row) {
            $copy = $row->replicate();
            $copy->contract_version = 2;
            $copy->uuid = $user->uuid;
            $copy->revision = 2;
            $copy->captured_at = now();
            $copy->save();
        }
    }

    private function pickReadyKit(EnterprisePurpose $purpose, bool $lock = false): ?EnterpriseInventoryKit
    {
        $query = EnterpriseInventoryKit::where('purpose_id', $purpose->id)
            ->where('status', EnterpriseInventoryKit::STATUS_READY)
            ->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $candidates = $query->get();
        // SB-ER-007: no silent fallback to a stale kit. When nothing is
        // fresh the caller queues the order (PAID_WAITING_INVENTORY);
        // handing out an unverified kit defeats the freshness gate the
        // design mandates.
        return $candidates->first(fn(EnterpriseInventoryKit $k) => $k->assignmentFresh());
    }

    private function customerLabel(User $user): string
    {
        $local = strstr($user->email, '@', true) ?: ('user' . $user->id);
        $label = preg_replace('/[^A-Za-z0-9_.-]+/', '-', $local) ?: ('user' . $user->id);
        return substr($label, 0, 24);
    }
}
