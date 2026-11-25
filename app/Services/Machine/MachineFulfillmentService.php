<?php

namespace App\Services\Machine;

use App\Models\EnterpriseInventoryAlert;
use App\Models\EnterpriseInventoryKit;
use App\Models\MachineFulfillment;
use App\Models\MachineFulfillmentLog;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\Enterprise\EnterpriseGroupMembershipService;
use App\Services\Enterprise\EnterpriseInventoryService;
use App\Services\ServerService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Business plans batch 3 — dedicated-VPS fulfilment state machine
 * (finalised §3.1–3.4 as amended by D07/D08):
 *
 *   WAITING_PAYMENT → PAID_PENDING_PURCHASE → PURCHASING → WAITING_IP
 *   → WAITING_INSTALL → INSTALLING → VERIFYING → DELIVERED
 *   exceptions: PURCHASE_BLOCKED / INSTALL_FAILED is tracked via
 *   VERIFY_FAILED for the verify leg / SUSPENDED / GRACE_HELD /
 *   RECLAIM_PENDING / RECLAIMED / CANCELLED (unpaid orders only).
 *
 * SLA: deadline = paid_at + 3h; T+90 (not yet installing) reminds the
 * admin, T+150 raises a high-priority alert, T+180 marks SLA_OVERDUE and
 * notifies the customer with exactly two choices (wait for the original
 * region / free switch to another region). 24h past the notice (or past
 * the extended deadline after a "wait" choice) without delivery sends
 * the fulfilment to termination review — an admin decision, never an
 * automatic refund: this flow has no refund branch at all (D08).
 *
 * Tier exclusivity (D06/D07): one account holds one business tier. The
 * old tier is only released once the new tier is actually delivered —
 * for the machine tier that happens in deliver(); for the port tier the
 * kit assignment in OrderService::open() is instantly ready, so the
 * machine side is superseded from there via supersedeActiveMachine().
 */
class MachineFulfillmentService
{
    public const DELIVERY_SLA_MINUTES = 180;
    public const GRACE_HOURS = 48;

    public function __construct(
        private MachineNodeProvisioner $provisioner,
        private MachineNodeProbe $probe,
        private EnterpriseGroupMembershipService $membership,
        private MachineNotificationService $notifications,
    ) {
    }

    // ------------------------------------------------------------ checkout

    /**
     * Create the WAITING_PAYMENT fulfilment for a machine-plan order.
     * Renewals (an active fulfilment already exists for this user+plan)
     * do not create a second record: the region and machine carry over.
     */
    public function createForOrder(Order $order, Plan $plan, ?string $regionCode, User $user): MachineFulfillment
    {
        // SB-ER-005: renewal targeting excludes RECLAIM_PENDING (see
        // MachineFulfillment::RENEWAL_STATUSES) — a repurchase during
        // reclaim creates a fresh fulfilment instead of being folded
        // into the record being dismantled.
        $active = $this->renewalForUser((int) $user->id, (int) $plan->id);
        if ($active) {
            if (!$order->machine_region_code) {
                $order->machine_region_code = $active->region_code;
                $order->save();
            }
            return $active;
        }

        $code = MachineRegionCatalog::normalize($regionCode);
        if (!$code) {
            throw new \InvalidArgumentException('Please select a region for the dedicated-VPS plan');
        }
        $fulfillment = MachineFulfillment::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'region_code' => $code,
            'region_name' => MachineRegionCatalog::name($code),
            'status' => MachineFulfillment::STATUS_WAITING_PAYMENT,
        ]);
        MachineFulfillmentLog::record($fulfillment->id, 'fulfillment.created', 'region ' . $code, MachineFulfillmentLog::ACTOR_USER, (int) $user->id);
        return $fulfillment;
    }

    public function activeForUser(int $userId, ?int $planId = null): ?MachineFulfillment
    {
        return MachineFulfillment::where('user_id', $userId)
            ->when($planId, fn($q) => $q->where('plan_id', $planId))
            ->whereIn('status', MachineFulfillment::ACTIVE_STATUSES)
            ->orderByDesc('id')
            ->first();
    }

    /** The fulfilment a new purchase would renew (SB-ER-005). */
    public function renewalForUser(int $userId, ?int $planId = null): ?MachineFulfillment
    {
        return MachineFulfillment::where('user_id', $userId)
            ->when($planId, fn($q) => $q->where('plan_id', $planId))
            ->whereIn('status', MachineFulfillment::RENEWAL_STATUSES)
            ->orderByDesc('id')
            ->first();
    }

    public function forOrder(int $orderId): ?MachineFulfillment
    {
        return MachineFulfillment::where('order_id', $orderId)->first();
    }

    // ------------------------------------------------------------ paid/open

    /**
     * Runs inside OrderService::open()'s transaction, after buyByPeriod()
     * wrote the plan template group. Advances a freshly paid fulfilment
     * and — crucially for renewals — restores the dedicated machine group
     * that buyByPeriod() just overwrote.
     */
    public function assignWithinOpen(Order $order, User $user, Plan $plan): ?MachineFulfillment
    {
        $fulfillment = $this->forOrder((int) $order->id) ?? $this->renewalForUser((int) $user->id, (int) $plan->id);
        if (!$fulfillment) {
            return null;
        }

        if ($fulfillment->status === MachineFulfillment::STATUS_WAITING_PAYMENT) {
            $this->markPaid($fulfillment, $order);
        } elseif ($fulfillment->status === MachineFulfillment::STATUS_DELIVERED && $fulfillment->group_id) {
            // Renewal: keep machine / nodes / group / subscription untouched.
            $user->group_id = $fulfillment->group_id;
            MachineFulfillmentLog::record($fulfillment->id, 'fulfillment.renewed', 'group restored on renewal');
        } elseif ($fulfillment->status === MachineFulfillment::STATUS_GRACE_HELD && $fulfillment->group_id) {
            // Renewal inside the 48h retention window restores delivery.
            $fulfillment->status = MachineFulfillment::STATUS_DELIVERED;
            $fulfillment->grace_until = null;
            $fulfillment->save();
            $user->group_id = $fulfillment->group_id;
            $user->machine_fulfillment_id = $fulfillment->id;
            $this->membership->addGroupToVipServers((int) $fulfillment->group_id);
            $this->restoreDedicatedNodeGrants($fulfillment);
            MachineFulfillmentLog::record($fulfillment->id, 'fulfillment.restored', 'renewed during grace; delivery restored');
        }

        return $fulfillment;
    }

    /** Post-commit hook for OrderService::cancel(). */
    public function cancelForOrder(Order $order): void
    {
        $fulfillment = $this->forOrder((int) $order->id);
        if ($fulfillment && $fulfillment->status === MachineFulfillment::STATUS_WAITING_PAYMENT) {
            $fulfillment->status = MachineFulfillment::STATUS_CANCELLED;
            $fulfillment->save();
            MachineFulfillmentLog::record($fulfillment->id, 'fulfillment.cancelled', 'unpaid order cancelled');
        }
    }

    private function markPaid(MachineFulfillment $fulfillment, Order $order): void
    {
        $paidAt = $order->paid_at ? Carbon::createFromTimestamp((int) $order->paid_at) : now();
        $fulfillment->paid_at = $paidAt;
        $fulfillment->delivery_deadline_at = $paidAt->copy()->addMinutes(self::DELIVERY_SLA_MINUTES);
        $fulfillment->status = MachineFulfillment::STATUS_PAID_PENDING_PURCHASE;
        $fulfillment->save();
        MachineFulfillmentLog::record($fulfillment->id, 'fulfillment.paid', 'deadline ' . $fulfillment->delivery_deadline_at);
    }

    // ------------------------------------------------------------ admin flow

    public function startPurchase(MachineFulfillment $f, ?int $adminId = null): MachineFulfillment
    {
        $this->assertStatus($f, [MachineFulfillment::STATUS_PAID_PENDING_PURCHASE, MachineFulfillment::STATUS_PURCHASE_BLOCKED]);
        return $this->transition($f, MachineFulfillment::STATUS_PURCHASING, 'fulfillment.purchasing', null, $adminId);
    }

    public function markBlocked(MachineFulfillment $f, ?string $note, ?int $adminId = null): MachineFulfillment
    {
        $this->assertStatus($f, MachineFulfillment::IN_FLIGHT_STATUSES);
        if ($note) {
            $f->admin_note = $note;
        }
        return $this->transition($f, MachineFulfillment::STATUS_PURCHASE_BLOCKED, 'fulfillment.purchase_blocked', $note, $adminId);
    }

    /**
     * Backfill the bought VPS (public IP + vendor facts), then provision:
     * WAITING_IP records the backfill, provisioning immediately follows
     * and lands the fulfilment in WAITING_INSTALL with the machine,
     * group and three nodes created.
     */
    public function backfillIp(MachineFulfillment $f, array $data, ?int $adminId = null): MachineFulfillment
    {
        $this->assertStatus($f, [
            MachineFulfillment::STATUS_PAID_PENDING_PURCHASE,
            MachineFulfillment::STATUS_PURCHASING,
            MachineFulfillment::STATUS_PURCHASE_BLOCKED,
            MachineFulfillment::STATUS_WAITING_IP,
        ]);
        $ip = trim((string) ($data['public_ip'] ?? ''));
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw new \InvalidArgumentException('public_ip must be a valid IP address');
        }
        $f->public_ip = $ip;
        $f->vendor_instance_id = $data['vendor_instance_id'] ?? null;
        $f->vendor_name = $data['vendor_name'] ?? null;
        $f->purchase_note = $data['purchase_note'] ?? null;
        $f->resource_expires_at = $data['resource_expires_at'] ?? null;
        $f->save();
        $this->transition($f, MachineFulfillment::STATUS_WAITING_IP, 'fulfillment.ip_backfilled', $ip, $adminId);

        $this->provisioner->provision($f);
        return $this->transition($f->fresh(), MachineFulfillment::STATUS_WAITING_INSTALL, 'fulfillment.provisioned', 'machine + group + 3 nodes created', $adminId);
    }

    /** Admin-triggered verification retry (also used by the tick). */
    public function retryVerify(MachineFulfillment $f, ?int $adminId = null): MachineFulfillment
    {
        $this->assertStatus($f, [MachineFulfillment::STATUS_VERIFY_FAILED, MachineFulfillment::STATUS_WAITING_INSTALL, MachineFulfillment::STATUS_INSTALLING]);
        return $this->runVerification($f->fresh());
    }

    public function suspend(MachineFulfillment $f, ?int $adminId = null): MachineFulfillment
    {
        $this->assertStatus($f, [MachineFulfillment::STATUS_DELIVERED]);
        // Incidental fix on the ER-002 path: $adminId was used inside
        // the closure without being captured (undefined-variable
        // warning in production, exception under strict handlers).
        DB::transaction(function () use ($f, $adminId) {
            $this->stopEntitlement($f);
            $this->transition($f, MachineFulfillment::STATUS_SUSPENDED, 'fulfillment.suspended', null, $adminId);
        });
        return $f->fresh();
    }

    public function resume(MachineFulfillment $f, ?int $adminId = null): MachineFulfillment
    {
        $this->assertStatus($f, [MachineFulfillment::STATUS_SUSPENDED]);
        $user = User::findOrFail($f->user_id);
        if ($user->expired_at !== null && $user->expired_at < time()) {
            throw new \InvalidArgumentException('user subscription has expired; cannot resume');
        }
        DB::transaction(function () use ($f, $user, $adminId) {
            $user->group_id = $f->group_id;
            $user->machine_fulfillment_id = $f->id;
            $user->save();
            $this->membership->addGroupToVipServers((int) $f->group_id);
            $this->restoreDedicatedNodeGrants($f);
            $this->transition($f, MachineFulfillment::STATUS_DELIVERED, 'fulfillment.resumed', null, $adminId);
        });
        return $f->fresh();
    }

    /**
     * SB-ER-002: stopEntitlement() strips the dedicated group from
     * EVERY server carrying it — including the fulfilment's three
     * dedicated nodes, whose only grant it is. The VIP-side restore
     * (addGroupToVipServers) never reaches those nodes, so a resumed /
     * grace-restored machine came back DELIVERED with its dedicated
     * lines invisible. Re-grant the group to the three nodes here.
     * Must be called inside a DB transaction (membership contract).
     */
    private function restoreDedicatedNodeGrants(MachineFulfillment $f): void
    {
        if (!$f->group_id) {
            return;
        }
        $ids = $f->nodeIds();
        if ($ids) {
            $this->membership->addGroupToServers((int) $f->group_id, $ids);
        }
    }

    // ------------------------------------------------------------ verify + deliver

    /**
     * INSTALLING detection + verification + delivery. A fulfilment in
     * WAITING_INSTALL whose machine has heartbeated since provisioning
     * moves to INSTALLING and is verified in the same pass.
     */
    public function detectInstallAndVerify(MachineFulfillment $f): MachineFulfillment
    {
        if ($f->status !== MachineFulfillment::STATUS_WAITING_INSTALL || !$f->machine_id) {
            return $f;
        }
        $machine = ServerMachine::find($f->machine_id);
        if (!$machine || !$machine->last_seen_at || !$f->provisioned_at) {
            return $f;
        }
        if ((int) $machine->last_seen_at < $f->provisioned_at->timestamp) {
            return $f;
        }
        $this->transition($f, MachineFulfillment::STATUS_INSTALLING, 'fulfillment.installing', 'agent heartbeat received');
        return $this->runVerification($f->fresh());
    }

    public function runVerification(MachineFulfillment $f): MachineFulfillment
    {
        $this->transition($f, MachineFulfillment::STATUS_VERIFYING, 'fulfillment.verifying', null);
        $f = $f->fresh();

        $report = [];
        $machine = $f->machine_id ? ServerMachine::find($f->machine_id) : null;
        $report['machine_online'] = $machine && $machine->last_seen_at && (int) $machine->last_seen_at >= now()->subMinutes(5)->timestamp;

        $nodes = Server::whereIn('id', $f->nodeIds())->get();
        $report['nodes_present'] = $nodes->count() === 3
            && $nodes->every(fn(Server $s) => (int) $s->machine_id === (int) $f->machine_id && $s->enabled);
        foreach (['reality' => $f->reality_server_id, 'ss1' => $f->ss1_server_id, 'sw1' => $f->sw1_server_id] as $role => $serverId) {
            $node = $nodes->firstWhere('id', $serverId);
            $report['tcp_' . $role] = $node && $f->public_ip
                ? $this->probe->tcpReachable((string) $f->public_ip, (int) $node->server_port)
                : false;
        }

        // SB-ER-010: TCP reachability says nothing about whether the
        // two TLS lines can ever obtain a certificate. Delivery now
        // also requires each TLS node's cert_config to carry a
        // complete DNS-01 credential set (provider / dns_env / email);
        // an incomplete set parks the fulfilment at VERIFY_FAILED with
        // the failing leg named in the report instead of delivering a
        // line that can never come up.
        foreach (['ss1' => $f->ss1_server_id, 'sw1' => $f->sw1_server_id] as $role => $serverId) {
            $node = $nodes->firstWhere('id', $serverId);
            $report['cert_source_' . $role] = $node
                ? \App\Services\CertCredentialReference::isComplete($node->cert_config)
                : false;
        }

        // Subscription visibility is checked the way delivery will render
        // it: an account inside the dedicated group must see exactly the
        // three dedicated nodes (the buyer's own group is only swapped at
        // deliver(), so probe with a synthetic group member).
        $member = new User();
        $member->group_id = $f->group_id;
        $member->uuid = (string) \Illuminate\Support\Str::uuid();
        $visible = $this->availableServerIds($member);
        $report['subscription_visible'] = count(array_intersect($f->nodeIds(), $visible)) === 3;

        // Isolation: an account outside the dedicated group must not see
        // the three dedicated nodes.
        $plan = Plan::find($f->plan_id);
        $outsider = new User();
        $outsider->group_id = $plan?->group_id;
        $outsider->uuid = (string) \Illuminate\Support\Str::uuid();
        $outsiderVisible = $this->availableServerIds($outsider);
        $report['isolation'] = count(array_intersect($f->nodeIds(), $outsiderVisible)) === 0;

        $f->verify_report = json_encode($report);
        $f->verified_at = now();
        $f->save();

        $ok = !in_array(false, $report, true);
        if (!$ok) {
            return $this->transition($f, MachineFulfillment::STATUS_VERIFY_FAILED, 'fulfillment.verify_failed', json_encode($report));
        }
        return $this->deliver($f);
    }

    private function availableServerIds(User $user): array
    {
        $servers = ServerService::getAvailableServers($user);
        return collect($servers)->map(fn($s) => (int) (is_array($s) ? $s['id'] : $s->id))->all();
    }

    /**
     * DELIVERED means the customer can actually use the lines: group swap
     * and VIP grant happen here, never earlier. If the customer still
     * holds the port tier, its kit is released into grace only now
     * (D06: the old tier is never stripped before the new one is ready).
     */
    public function deliver(MachineFulfillment $f): MachineFulfillment
    {
        DB::transaction(function () use ($f) {
            $f = MachineFulfillment::lockForUpdate()->find($f->id);
            $user = User::lockForUpdate()->find($f->user_id);
            if (!$user) {
                throw new \RuntimeException('fulfilment user missing');
            }
            if ($user->enterprise_kit_id) {
                $this->releaseKitForTierSwitch($user);
            }
            $user->group_id = $f->group_id;
            $user->machine_fulfillment_id = $f->id;
            $user->save();
            $this->membership->addGroupToVipServers((int) $f->group_id);
            $f->delivered_at = now();
            $this->transition($f, MachineFulfillment::STATUS_DELIVERED, 'fulfillment.delivered', 'group + VIP granted');
        });
        $fresh = $f->fresh();
        $this->notifications->delivered($fresh);
        return $fresh;
    }

    private function releaseKitForTierSwitch(User $user): void
    {
        $kit = EnterpriseInventoryKit::lockForUpdate()->find($user->enterprise_kit_id);
        if (!$kit || !in_array($kit->status, [EnterpriseInventoryKit::STATUS_ASSIGNED, EnterpriseInventoryKit::STATUS_ASSIGNED_LEGACY], true)) {
            $user->enterprise_kit_id = null;
            return;
        }
        $kit->grace_until = now()->addDays(7);
        $kit->save();
        app(EnterpriseInventoryService::class)->transition(
            $kit,
            EnterpriseInventoryKit::STATUS_GRACE_HELD,
            'kit.tier_switch',
            'customer switched to the dedicated-VPS tier; kit released into grace'
        );
        $user->enterprise_kit_id = null;
    }

    /**
     * Called from OrderService::open() when a port-tier (kit) assignment
     * has just completed for a customer whose machine tier is DELIVERED:
     * the kit is instantly usable, so the switch completes and the
     * machine enters its 48h retention window.
     */
    public function supersedeActiveMachine(User $user, string $reason): void
    {
        $f = MachineFulfillment::where('user_id', $user->id)
            ->where('status', MachineFulfillment::STATUS_DELIVERED)
            ->orderByDesc('id')
            ->first();
        if (!$f) {
            return;
        }
        DB::transaction(function () use ($f, $reason) {
            $this->stopEntitlement($f);
            $f->grace_until = now()->addHours(self::GRACE_HOURS);
            $f->save();
            $this->transition($f, MachineFulfillment::STATUS_GRACE_HELD, 'fulfillment.superseded', $reason);
        });
    }

    // ------------------------------------------------------------ expiry + reclaim

    /** Remove the customer's access to the dedicated group + VIP grant. */
    private function stopEntitlement(MachineFulfillment $f): void
    {
        if ($f->group_id) {
            $this->membership->removeGroupFromVipServers((int) $f->group_id);
        }
        $user = User::lockForUpdate()->find($f->user_id);
        if ($user && (int) $user->group_id === (int) $f->group_id) {
            $plan = Plan::find($f->plan_id);
            $user->group_id = $plan?->group_id;
            $user->save();
        }
    }

    private function beginGrace(MachineFulfillment $f, Carbon $graceUntil, string $event, string $detail): void
    {
        DB::transaction(function () use ($f, $graceUntil, $event, $detail) {
            $this->stopEntitlement($f);
            $f->grace_until = $graceUntil;
            $f->save();
            $this->transition($f, MachineFulfillment::STATUS_GRACE_HELD, $event, $detail);
        });
    }

    /** One reclaim checklist step (admin-driven, strictly in order). */
    public function reclaimStep(MachineFulfillment $f, string $step, ?int $adminId = null, ?string $note = null): MachineFulfillment
    {
        $this->assertStatus($f, [MachineFulfillment::STATUS_RECLAIM_PENDING, MachineFulfillment::STATUS_GRACE_HELD]);
        if ($f->status === MachineFulfillment::STATUS_GRACE_HELD) {
            $this->moveToReclaimPending($f);
            $f = $f->fresh();
        }
        $checklist = $f->reclaim_checklist ?? [];
        $steps = MachineFulfillment::RECLAIM_STEPS;
        $idx = array_search($step, $steps, true);
        if ($idx === false) {
            throw new \InvalidArgumentException('unknown reclaim step: ' . $step);
        }
        foreach (array_slice($steps, 0, $idx) as $prior) {
            if (empty($checklist[$prior]['done'])) {
                throw new \InvalidArgumentException('reclaim step out of order: ' . $prior . ' is not done');
            }
        }

        if ($step === 'token_and_grants_revoked') {
            $machine = $f->machine_id ? ServerMachine::find($f->machine_id) : null;
            if ($machine) {
                $machine->token = ServerMachine::generateToken();
                $machine->is_active = false;
                $machine->save();
            }
            Server::whereIn('id', $f->nodeIds())->update(['enabled' => false, 'show' => false]);
            if ($f->group_id) {
                $this->membership->removeGroupFromVipServers((int) $f->group_id);
            }
        }

        $checklist[$step] = ['done' => true, 'at' => now()->toDateTimeString(), 'by' => $adminId, 'note' => $note];
        $f->reclaim_checklist = $checklist;
        $f->save();
        MachineFulfillmentLog::record($f->id, 'fulfillment.reclaim_step', $step, MachineFulfillmentLog::ACTOR_ADMIN, $adminId);

        if ($step === 'reclaim_recorded') {
            $user = User::find($f->user_id);
            if ($user && (int) $user->machine_fulfillment_id === (int) $f->id) {
                $user->machine_fulfillment_id = null;
                $user->save();
            }
            $f->reclaimed_at = now();
            $this->transition($f, MachineFulfillment::STATUS_RECLAIMED, 'fulfillment.reclaimed', null, $adminId);
        }
        return $f->fresh();
    }

    private function moveToReclaimPending(MachineFulfillment $f): void
    {
        $checklist = $f->reclaim_checklist ?? [];
        $checklist['entitlement_stopped'] = ['done' => true, 'at' => now()->toDateTimeString(), 'by' => null, 'note' => 'auto: entitlement stopped at grace'];
        $checklist['retention_elapsed'] = ['done' => true, 'at' => now()->toDateTimeString(), 'by' => null, 'note' => 'auto: 48h retention elapsed'];
        $f->reclaim_checklist = $checklist;
        $f->save();
        $this->transition($f, MachineFulfillment::STATUS_RECLAIM_PENDING, 'fulfillment.reclaim_pending', 'retention elapsed');
    }

    // ------------------------------------------------------------ customer choice

    /** The customer's answer to the SLA-overdue notice (two choices only). */
    public function recordUserChoice(MachineFulfillment $f, User $user, string $choice, ?string $newRegion = null): MachineFulfillment
    {
        if ((int) $f->user_id !== (int) $user->id) {
            throw new \InvalidArgumentException('fulfilment does not belong to this user');
        }
        if (!$f->sla_overdue || !$f->isInFlight()) {
            throw new \InvalidArgumentException('fulfilment is not awaiting a choice');
        }
        if ($choice === MachineFulfillment::CHOICE_WAIT_ORIGINAL) {
            $f->user_choice = $choice;
            $f->user_choice_at = now();
            $f->extended_deadline_at = now()->addHours(24);
            $f->save();
            MachineFulfillmentLog::record($f->id, 'fulfillment.choice_wait', 'customer keeps waiting for the original region (+24h)', MachineFulfillmentLog::ACTOR_USER, (int) $user->id);
            $this->adminAlert('machine_choice_wait', "Fulfilment #{$f->id} (user {$f->user_id}): customer chose to keep waiting for region {$f->region_code}; extended deadline {$f->extended_deadline_at}");
        } elseif ($choice === MachineFulfillment::CHOICE_SWITCH_REGION) {
            $code = MachineRegionCatalog::normalize($newRegion);
            if (!$code || $code === $f->region_code) {
                throw new \InvalidArgumentException('a different, valid region is required to switch');
            }
            $from = $f->region_code;
            // SB-ER-004: a region switch after provisioning must not
            // keep pointing at the old region's machine. Previously
            // only the region label changed: the fulfilment kept the
            // old machine, group, nodes and verification data, and the
            // next IP backfill short-circuited in provision() (existing
            // machine_id) — the new region's IP was recorded while the
            // subscription still served the old box. Tear the old
            // resources down (same revocation shape as the reclaim
            // flow) and clear every provisioned pointer so the new
            // region provisions fresh.
            // SB-ER-004-R1: EVERY database change of the switch — the
            // teardown, the pointer clearing, the region/status update
            // and both audit rows — runs in ONE transaction on the
            // locked fulfilment row, and the final save's return value
            // is checked. The first ER-004 shape committed the teardown
            // in an inner transaction and saved the fulfilment outside
            // it, unchecked: a failed final save left the old machine
            // revoked while the fulfilment still pointed at it.
            DB::transaction(function () use ($f, $user, $choice, $code, &$from) {
                $locked = MachineFulfillment::lockForUpdate()->find($f->id);
                if (!$locked
                    || (int) $locked->user_id !== (int) $user->id
                    || !$locked->sla_overdue
                    || !$locked->isInFlight()
                    || $locked->region_code === $code) {
                    throw new \InvalidArgumentException('fulfilment is no longer awaiting this choice');
                }
                $from = $locked->region_code;
                if ($locked->machine_id) {
                    $machine = ServerMachine::find($locked->machine_id);
                    if ($machine) {
                        $machine->token = ServerMachine::generateToken();
                        $machine->is_active = false;
                        $machine->save();
                    }
                    Server::whereIn('id', $locked->nodeIds())->update(['enabled' => false, 'show' => false]);
                    if ($locked->group_id) {
                        $this->membership->removeGroupFromVipServers((int) $locked->group_id);
                    }
                    $locked->machine_id = null;
                    $locked->group_id = null;
                    $locked->reality_server_id = null;
                    $locked->ss1_server_id = null;
                    $locked->sw1_server_id = null;
                    $locked->public_ip = null;
                    $locked->vendor_instance_id = null;
                    $locked->vendor_name = null;
                    $locked->resource_expires_at = null;
                    $locked->provisioned_at = null;
                    $locked->verified_at = null;
                    $locked->verify_report = null;
                    MachineFulfillmentLog::record($locked->id, 'fulfillment.region_teardown', 'provisioned resources revoked for region switch');
                }
                $locked->region_code = $code;
                $locked->region_name = MachineRegionCatalog::name($code);
                $locked->user_choice = $choice;
                $locked->user_choice_at = now();
                $locked->sla_overdue = false;
                $locked->sla_stage = 0;
                $locked->overdue_notice_at = null;
                $locked->extended_deadline_at = null;
                $locked->termination_review = false;
                $locked->delivery_deadline_at = now()->addMinutes(self::DELIVERY_SLA_MINUTES);
                $locked->status = MachineFulfillment::STATUS_PAID_PENDING_PURCHASE;
                if (!$locked->save()) {
                    throw new \RuntimeException('failed to persist region switch for fulfilment #' . $locked->id);
                }
                MachineFulfillmentLog::record($locked->id, 'fulfillment.choice_switch', "customer switched region {$from} -> {$code}; SLA clock restarted", MachineFulfillmentLog::ACTOR_USER, (int) $user->id);
            });
            $this->adminAlert('machine_choice_switch', "Fulfilment #{$f->id} (user {$f->user_id}): customer switched region {$from} -> {$code}");
        } else {
            throw new \InvalidArgumentException('unknown choice: ' . $choice);
        }
        return $f->fresh();
    }

    // ------------------------------------------------------------ tick

    /** All time-driven processing; run every few minutes by the scheduler. */
    public function tick(): array
    {
        $stats = ['paid_synced' => 0, 'installed' => 0, 'sla' => 0, 'expired' => 0, 'reclaim_pending' => 0, 'lease_alerts' => 0];

        // Safety net: a paid order whose open() hook did not run still
        // advances its fulfilment.
        MachineFulfillment::where('status', MachineFulfillment::STATUS_WAITING_PAYMENT)->get()
            ->each(function (MachineFulfillment $f) use (&$stats) {
                $order = Order::find($f->order_id);
                if ($order && in_array((int) $order->status, [Order::STATUS_PROCESSING, Order::STATUS_COMPLETED], true)) {
                    $this->markPaid($f, $order);
                    $stats['paid_synced']++;
                }
            });

        MachineFulfillment::where('status', MachineFulfillment::STATUS_WAITING_INSTALL)->get()
            ->each(function (MachineFulfillment $f) use (&$stats) {
                $before = $f->status;
                $this->detectInstallAndVerify($f);
                if ($f->fresh()->status !== $before) {
                    $stats['installed']++;
                }
            });

        MachineFulfillment::whereIn('status', MachineFulfillment::IN_FLIGHT_STATUSES)->get()
            ->each(function (MachineFulfillment $f) use (&$stats) {
                if ($this->slaTick($f)) {
                    $stats['sla']++;
                }
            });

        // Expiry: entitlement stops at once, VPS retained 48h.
        MachineFulfillment::where('status', MachineFulfillment::STATUS_DELIVERED)->get()
            ->each(function (MachineFulfillment $f) use (&$stats) {
                $user = User::find($f->user_id);
                if ($user && (int) $user->plan_id === (int) $f->plan_id && $user->expired_at !== null && $user->expired_at < time()) {
                    $this->beginGrace($f, Carbon::createFromTimestamp((int) $user->expired_at)->addHours(self::GRACE_HOURS), 'fulfillment.expired', 'subscription expired; 48h retention started');
                    $stats['expired']++;
                }
            });

        MachineFulfillment::where('status', MachineFulfillment::STATUS_GRACE_HELD)
            ->whereNotNull('grace_until')->where('grace_until', '<', now())->get()
            ->each(function (MachineFulfillment $f) use (&$stats) {
                // Port-tier kits in grace belong to the enterprise lifecycle;
                // only machine fulfilments are reclaimed here.
                $this->moveToReclaimPending($f);
                $stats['reclaim_pending']++;
            });

        // Vendor lease reminders for delivered machines.
        MachineFulfillment::where('status', MachineFulfillment::STATUS_DELIVERED)
            ->whereNotNull('resource_expires_at')
            ->where('resource_expires_at', '<=', now()->addDays(7))
            ->get()
            ->each(function (MachineFulfillment $f) use (&$stats) {
                if ($f->lease_alert_at && $f->lease_alert_at->gt(now()->subDay())) {
                    return;
                }
                $this->adminAlert('machine_lease_expiring', "Fulfilment #{$f->id}: vendor VPS lease expires at {$f->resource_expires_at}; renew the lease together with the subscription");
                $f->lease_alert_at = now();
                $f->save();
                $stats['lease_alerts']++;
            });

        return $stats;
    }

    /** SLA stages for one in-flight fulfilment. Returns true if anything fired. */
    private function slaTick(MachineFulfillment $f): bool
    {
        if (!$f->delivery_deadline_at) {
            return false;
        }
        $fired = false;
        $base = $f->delivery_deadline_at->copy()->subMinutes(self::DELIVERY_SLA_MINUTES);
        $now = now();

        // T+90: not yet installing → remind the admin.
        if ((int) $f->sla_stage < 1 && $now->gte($base->copy()->addMinutes(90))
            && !in_array($f->status, [MachineFulfillment::STATUS_INSTALLING, MachineFulfillment::STATUS_VERIFYING], true)) {
            $f->sla_stage = 1;
            $f->save();
            $this->adminAlert('machine_sla_90', "Fulfilment #{$f->id} (user {$f->user_id}, region {$f->region_code}) has not reached installation 90 minutes after payment");
            MachineFulfillmentLog::record($f->id, 'fulfillment.sla_90', 'admin reminded');
            $fired = true;
        }
        // T+150: still undelivered → high-priority alert.
        if ((int) $f->sla_stage < 2 && $now->gte($base->copy()->addMinutes(150))) {
            $f->sla_stage = 2;
            $f->save();
            $this->adminAlert('machine_sla_150', "HIGH PRIORITY: fulfilment #{$f->id} (user {$f->user_id}, region {$f->region_code}) is still undelivered 150 minutes after payment");
            MachineFulfillmentLog::record($f->id, 'fulfillment.sla_150', 'high-priority alert raised');
            $fired = true;
        }
        // T+180: overdue → mark + notify the customer (two choices).
        if ((int) $f->sla_stage < 3 && $now->gte($f->delivery_deadline_at)) {
            $f->sla_stage = 3;
            $f->sla_overdue = true;
            $f->overdue_notice_at = $now;
            $f->last_progress_notice_at = $now;
            $f->save();
            $this->notifications->overdueNotice($f);
            $this->adminAlert('machine_sla_overdue', "Fulfilment #{$f->id} (user {$f->user_id}, region {$f->region_code}) passed the 3h delivery deadline; customer notified with the two choices");
            MachineFulfillmentLog::record($f->id, 'fulfillment.sla_overdue', 'customer notified');
            $fired = true;
        }

        if ($f->sla_overdue && $f->isInFlight()) {
            // Progress updates every 6h while overdue.
            $last = $f->last_progress_notice_at ?? $f->overdue_notice_at;
            if ($last && $now->gte($last->copy()->addHours(6))) {
                $f->last_progress_notice_at = $now;
                $f->save();
                $this->notifications->progressUpdate($f);
                MachineFulfillmentLog::record($f->id, 'fulfillment.progress_update', '6h progress notice sent');
                $fired = true;
            }
            // Termination review: 24h after the notice without a choice,
            // or past the extended deadline after a "wait" choice. Admin
            // decides; nothing here refunds or re-purchases automatically.
            if (!$f->termination_review) {
                $reviewAt = $f->user_choice === MachineFulfillment::CHOICE_WAIT_ORIGINAL && $f->extended_deadline_at
                    ? $f->extended_deadline_at
                    : $f->overdue_notice_at?->copy()->addHours(24);
                if ($reviewAt && $now->gte($reviewAt)) {
                    $f->termination_review = true;
                    $f->save();
                    $this->adminAlert('machine_termination_review', "Fulfilment #{$f->id} (user {$f->user_id}, region {$f->region_code}) entered termination review: still undelivered after the extended window; no new purchase, no refund — admin to decide");
                    MachineFulfillmentLog::record($f->id, 'fulfillment.termination_review', 'entered termination review');
                    $fired = true;
                }
            }
        }
        return $fired;
    }

    // ------------------------------------------------------------ helpers

    private function transition(MachineFulfillment $f, string $to, string $event, ?string $detail = null, ?int $adminId = null): MachineFulfillment
    {
        $from = $f->status;
        $f->status = $to;
        $f->save();
        MachineFulfillmentLog::record($f->id, $event, $detail ?? "{$from} -> {$to}", $adminId ? MachineFulfillmentLog::ACTOR_ADMIN : MachineFulfillmentLog::ACTOR_SYSTEM, $adminId);
        return $f;
    }

    private function assertStatus(MachineFulfillment $f, array $allowed): void
    {
        if (!in_array($f->status, $allowed, true)) {
            throw new \InvalidArgumentException("fulfilment #{$f->id} is {$f->status}; expected one of " . implode(',', $allowed));
        }
    }

    private function adminAlert(string $type, string $message): void
    {
        $exists = EnterpriseInventoryAlert::where('alert_type', $type)
            ->where('status', EnterpriseInventoryAlert::STATUS_OPEN)
            ->where('message', $message)
            ->exists();
        if (!$exists) {
            EnterpriseInventoryAlert::create([
                'purpose_id' => null,
                'kit_id' => null,
                'alert_type' => $type,
                'message' => $message,
                'status' => EnterpriseInventoryAlert::STATUS_OPEN,
            ]);
        }
    }
}
