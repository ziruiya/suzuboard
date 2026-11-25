<?php

namespace Tests\Feature\Enterprise;

use App\Models\EnterpriseInventoryAlert;
use App\Models\EnterpriseInventoryKit;
use App\Models\EnterpriseOrderIntent;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Services\Enterprise\EnterpriseAssignmentService;
use App\Services\Enterprise\EnterpriseGroupMembershipService;
use App\Services\Enterprise\EnterpriseInventoryHealthService;
use App\Services\Enterprise\EnterpriseLifecycleService;
use App\Services\OrderService;
use App\Services\ServerService;

/**
 * SB-ENT-INV-01 acceptance items E01–E14, each as its own test.
 * No-Go items: E01, E02, E04, E11.
 */
class EnterpriseInventoryFlowTest extends EnterpriseTestCase
{
    private function purchaseAndPay($user, ?string $purposeCode = 'JP_TIKTOK'): Order
    {
        $order = OrderService::createFromRequest(
            $user,
            $this->enterprisePlan,
            Plan::PERIOD_MONTHLY,
            null,
            $purposeCode
        );
        (new OrderService($order))->paid('CB-' . $order->trade_no);
        return $order->fresh();
    }

    private function kitNodeIds(EnterpriseInventoryKit $kit): array
    {
        return $kit->nodes()->pluck('server_id')->map(fn($id) => (int) $id)->all();
    }

    /** E01 (No-Go): purpose → pay → kit group (not template 5), kit nodes + VIP visible, delivery verified. */
    public function test_e01_full_purchase_assigns_kit_and_vip_access(): void
    {
        $vip = $this->makeVipServer();
        $kit = $this->buildReadyKit();
        $this->registerRulePair(); // stock for the post-assignment replenishment build
        $user = $this->makeUser();

        $order = $this->purchaseAndPay($user);

        $user = $user->fresh();
        $kit = $kit->fresh();
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->status);
        $this->assertSame(EnterpriseInventoryKit::STATUS_ASSIGNED, $kit->status);
        // Hard constraint 1: the template group 5 write-back was overridden.
        $this->assertSame($kit->stock_group_id, (int) $user->group_id);
        $this->assertNotSame($this->templateGroup->id, (int) $user->group_id);
        $this->assertSame($kit->id, (int) $user->enterprise_kit_id);
        $this->assertSame($kit->id, (int) $order->enterprise_kit_id);
        $this->assertSame('JP_TIKTOK', $order->enterprise_purpose_code);

        $visible = $this->subscriptionServerIds($user);
        foreach ($this->kitNodeIds($kit) as $nodeId) {
            $this->assertContains($nodeId, $visible, 'assigned kit node must be in the subscription');
        }
        $this->assertContains($vip->id, $visible, 'VIP lines must be visible to the enterprise customer');

        $intent = EnterpriseOrderIntent::where('order_id', $order->id)->first();
        $this->assertNotNull($intent);
        $this->assertSame(
            EnterpriseOrderIntent::STATUS_DELIVERED,
            $intent->status,
            'sync delivery verification should mark the intent DELIVERED'
        );
        // Group and nodes renamed from [STOCK] to [ENT] <customer>.
        $this->assertStringStartsWith('[ENT]', $kit->group->name);
        foreach ($kit->nodes as $node) {
            $this->assertStringStartsWith('[ENT]', $node->server->name);
            $this->assertTrue((bool) $node->server->show);
        }
    }

    /** E02 (No-Go): subscription isolation — no other kit's nodes, no template-group nodes. */
    public function test_e02_subscription_isolated_from_other_kits_and_template(): void
    {
        $kitA = $this->buildReadyKit();
        $kitB = $this->buildReadyKit();
        $this->registerRulePair();
        $templateNode = $this->makePlainServer([$this->templateGroup->id], ['name' => '模板组节点']);
        $user = $this->makeUser();

        $this->purchaseAndPay($user);

        $assigned = EnterpriseInventoryKit::find($user->fresh()->enterprise_kit_id);
        $this->assertSame($kitA->id, $assigned->id, 'FIFO: the oldest READY kit is assigned first');
        $visible = $this->subscriptionServerIds($user);
        foreach ($this->kitNodeIds($kitB) as $nodeId) {
            $this->assertNotContains($nodeId, $visible, "another kit's node leaked into the subscription");
        }
        $this->assertNotContains($templateNode->id, $visible, 'template-group node leaked into the subscription');
    }

    /** E03: assignment triggers event-driven replenishment for the same SKU. */
    public function test_e03_assignment_triggers_replenishment(): void
    {
        $kit = $this->buildReadyKit();
        $this->registerRulePair(); // one pair for the replenishment build
        $user = $this->makeUser();

        $this->purchaseAndPay($user);

        $others = EnterpriseInventoryKit::where('purpose_id', $this->purpose->id)
            ->where('id', '!=', $kit->id)
            ->get();
        $this->assertCount(1, $others, 'replenishment should have built exactly one replacement kit');
        $this->assertContains($others->first()->status, [
            EnterpriseInventoryKit::STATUS_READY,
            EnterpriseInventoryKit::STATUS_VERIFYING,
            EnterpriseInventoryKit::STATUS_BUILDING,
        ]);
    }

    /** E04 (No-Go): duplicate paid()/open() never double-assigns; VIP group merge is idempotent. */
    public function test_e04_duplicate_payment_is_idempotent(): void
    {
        $vip = $this->makeVipServer();
        $kit = $this->buildReadyKit();
        $this->registerRulePair();
        $user = $this->makeUser();

        $order = OrderService::createFromRequest($user, $this->enterprisePlan, Plan::PERIOD_MONTHLY, null, 'JP_TIKTOK');
        $service = new OrderService($order);
        $service->paid('CB-1');
        $service->paid('CB-2');                       // duplicate callback
        (new OrderService($order->fresh()))->open();  // duplicate open attempt

        $assigned = EnterpriseInventoryKit::where('status', EnterpriseInventoryKit::STATUS_ASSIGNED)
            ->where('current_user_id', $user->id)
            ->get();
        $this->assertCount(1, $assigned, 'exactly one kit may be assigned');
        $this->assertSame(1, EnterpriseOrderIntent::where('order_id', $order->id)->count());

        $groupIds = array_map('strval', $vip->fresh()->group_ids ?? []);
        $this->assertSame(
            1,
            count(array_keys($groupIds, (string) $kit->stock_group_id, true)),
            'kit group must appear exactly once in the VIP server group_ids'
        );

        // The merge service itself is idempotent under repetition.
        $membership = app(EnterpriseGroupMembershipService::class);
        $membership->addGroupToVipServers($kit->stock_group_id);
        $membership->addGroupToVipServers($kit->stock_group_id);
        $groupIds = array_map('strval', $vip->fresh()->group_ids ?? []);
        $this->assertSame(1, count(array_keys($groupIds, (string) $kit->stock_group_id, true)));
    }

    /** E05: an unpaid HELD kit returns to READY after the 30-minute window. */
    public function test_e05_hold_expires_back_to_ready(): void
    {
        $kit = $this->buildReadyKit();
        $user = $this->makeUser();
        $order = OrderService::createFromRequest($user, $this->enterprisePlan, Plan::PERIOD_MONTHLY, null, 'JP_TIKTOK');

        $intent = EnterpriseOrderIntent::where('order_id', $order->id)->first();
        $this->assertSame(EnterpriseOrderIntent::STATUS_HELD, $intent->status);
        $this->assertSame(EnterpriseInventoryKit::STATUS_HELD, $kit->fresh()->status);

        $intent->hold_expires_at = now()->subMinute();
        $intent->save();

        app(EnterpriseLifecycleService::class)->releaseExpiredHolds();

        $this->assertSame(EnterpriseInventoryKit::STATUS_READY, $kit->fresh()->status);
        $this->assertSame(EnterpriseOrderIntent::STATUS_FAILED, $intent->fresh()->status);
    }

    /** E06 (revised): paid with no stock queues; >24h escalates an alert (never a refund); stock arrival drains FIFO. */
    public function test_e06_waiting_queue_escalates_then_drains(): void
    {
        $user = $this->makeUser();
        $order = $this->purchaseAndPay($user); // no kits exist at all

        $intent = EnterpriseOrderIntent::where('order_id', $order->id)->first();
        $this->assertSame(EnterpriseOrderIntent::STATUS_PAID_WAITING_INVENTORY, $intent->status);
        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->status, 'order stays completed — no refund path');

        $intent->waiting_since = now()->subHours(25);
        $intent->save();
        app(EnterpriseLifecycleService::class)->escalateWaitingIntents();
        $this->assertTrue(
            EnterpriseInventoryAlert::where('alert_type', EnterpriseInventoryAlert::TYPE_WAITING_TOO_LONG)->exists(),
            'a >24h wait must raise the escalation alert'
        );

        $kit = $this->buildReadyKit();
        $this->registerRulePair();
        $drained = app(EnterpriseLifecycleService::class)->drainWaitingIntents();
        $this->assertSame(1, $drained);
        $this->assertSame(EnterpriseInventoryKit::STATUS_ASSIGNED, $kit->fresh()->status);
        $this->assertSame($kit->stock_group_id, (int) $user->fresh()->group_id);
        $this->assertSame(EnterpriseOrderIntent::STATUS_DELIVERED, $intent->fresh()->status);
    }

    /** E07: expiry → 7-day grace → renewal inside grace restores the SAME kit. */
    public function test_e07_grace_then_renewal_restores_same_kit(): void
    {
        $kit = $this->buildReadyKit();
        $this->registerRulePair();
        $this->registerRulePair();
        $user = $this->makeUser();
        $this->purchaseAndPay($user);
        $groupBefore = (int) $user->fresh()->group_id;

        $user->expired_at = time() - 3600;
        $user->save();
        app(EnterpriseLifecycleService::class)->expireAssignedKits();
        $kit = $kit->fresh();
        $this->assertSame(EnterpriseInventoryKit::STATUS_GRACE_HELD, $kit->status);
        $this->assertNotNull($kit->grace_until);

        // Renewal order: purpose is derived from the existing kit, none passed.
        $order2 = $this->purchaseAndPay($user->fresh(), null);

        $kit = $kit->fresh();
        $this->assertSame(EnterpriseInventoryKit::STATUS_ASSIGNED, $kit->status);
        $this->assertNull($kit->grace_until);
        $this->assertSame($kit->id, (int) $order2->enterprise_kit_id);
        $this->assertSame($groupBefore, (int) $user->fresh()->group_id);
    }

    /** E08: manual revocation immediately cuts the customer's access. */
    public function test_e08_revocation_cuts_access_immediately(): void
    {
        $vip = $this->makeVipServer();
        $kit = $this->buildReadyKit();
        $this->registerRulePair();
        $user = $this->makeUser();
        $this->purchaseAndPay($user);
        $this->assertContains($this->kitNodeIds($kit)[0], $this->subscriptionServerIds($user));

        app(EnterpriseAssignmentService::class)->revokeAssignment($kit->fresh(), 1);

        $user = $user->fresh();
        $this->assertNull($user->enterprise_kit_id);
        $visible = $this->subscriptionServerIds($user);
        foreach ($this->kitNodeIds($kit) as $nodeId) {
            $this->assertNotContains($nodeId, $visible, 'revoked kit node still visible');
        }
        $groupIds = array_map('strval', $vip->fresh()->group_ids ?? []);
        $this->assertNotContains((string) $kit->stock_group_id, $groupIds, 'VIP server still carries the revoked kit group');
        $this->assertSame(EnterpriseInventoryKit::STATUS_QUARANTINED, $kit->fresh()->status);
    }

    /** E09: three consecutive failed health checks quarantine a READY kit and alert. */
    public function test_e09_three_health_failures_quarantine_kit(): void
    {
        $kit = $this->buildReadyKit();
        $this->probe->healthy = false;

        $health = app(EnterpriseInventoryHealthService::class);
        $health->verifyKit($kit->fresh(), 'full');
        $health->verifyKit($kit->fresh(), 'full');
        $this->assertSame(EnterpriseInventoryKit::STATUS_READY, $kit->fresh()->status);
        $health->verifyKit($kit->fresh(), 'full');

        $this->assertSame(EnterpriseInventoryKit::STATUS_QUARANTINED, $kit->fresh()->status);
        $this->assertTrue(
            EnterpriseInventoryAlert::where('kit_id', $kit->id)
                ->where('alert_type', EnterpriseInventoryAlert::TYPE_HEALTH_QUARANTINED)
                ->exists()
        );
    }

    /** E10 (hard constraint 2): two assigned kits coexist in VIP group_ids — no overwrite. */
    public function test_e10_two_kits_merge_into_vip_group_ids(): void
    {
        $vip = $this->makeVipServer();
        $kitA = $this->buildReadyKit();
        $kitB = $this->buildReadyKit();
        $this->registerRulePair();
        $this->registerRulePair();

        $this->purchaseAndPay($this->makeUser());
        $this->purchaseAndPay($this->makeUser());

        $groupIds = array_map('strval', $vip->fresh()->group_ids ?? []);
        $this->assertContains((string) $this->vipGroup->id, $groupIds);
        $this->assertContains((string) $kitA->stock_group_id, $groupIds);
        $this->assertContains((string) $kitB->stock_group_id, $groupIds);
        $this->assertSame(count($groupIds), count(array_unique($groupIds)), 'group_ids must stay deduplicated');
    }

    /**
     * E11 (No-Go): unassigned stock nodes are invisible in subscriptions
     * and expose no users — the kit group is empty until the probe
     * identity (verification) or the assigned customer joins it, and the
     * global-token agent paths refuse them outright (AgentNodeVisibilityTest).
     */
    public function test_e11_stock_nodes_invisible_and_userless(): void
    {
        $kit = $this->buildReadyKit();
        $other = $this->makeUser(['group_id' => $this->ordinaryGroup->id, 'expired_at' => time() + 86400]);

        $visible = $this->subscriptionServerIds($other);
        foreach ($kit->nodes as $node) {
            $this->assertNotContains($node->server_id, $visible, 'stock node leaked to an ordinary user');
            $users = ServerService::getAvailableUsers(Server::find($node->server_id));
            $this->assertCount(0, $users, 'a stock node must have no available users before assignment');
        }
    }

    /** E12: renewal keeps group, node ids, entry host/port and the user UUID identical. */
    public function test_e12_renewal_keeps_contract_identical(): void
    {
        $kit = $this->buildReadyKit();
        $this->registerRulePair();
        $this->registerRulePair();
        $user = $this->makeUser();
        $this->purchaseAndPay($user);

        $before = [
            'group_id' => (int) $user->fresh()->group_id,
            'uuid' => $user->fresh()->uuid,
            'nodes' => collect($kit->fresh()->nodes)->map(fn($n) => [
                'server_id' => $n->server_id,
                'host' => $n->server->host,
                'port' => (string) $n->server->port,
            ])->all(),
        ];

        $this->purchaseAndPay($user->fresh(), null);

        $after = [
            'group_id' => (int) $user->fresh()->group_id,
            'uuid' => $user->fresh()->uuid,
            'nodes' => collect($kit->fresh()->nodes)->map(fn($n) => [
                'server_id' => $n->server_id,
                'host' => $n->server->host,
                'port' => (string) $n->server->port,
            ])->all(),
        ];
        $this->assertSame($before, $after, 'renewal must not change the delivered contract');
    }

    /** E13: with zero READY rules the build stalls in BUILDING with an alert; paid orders queue instead of assigning. */
    public function test_e13_no_rules_stalls_build_and_queues_orders(): void
    {
        $builder = app(\App\Services\Enterprise\EnterpriseKitBuilderService::class);
        $kit = $builder->buildKit($this->purpose);
        $this->assertSame(EnterpriseInventoryKit::STATUS_BUILDING, $kit->status);
        $this->assertTrue(
            EnterpriseInventoryAlert::where('alert_type', EnterpriseInventoryAlert::TYPE_RULE_STOCK_EMPTY)->exists()
        );

        $user = $this->makeUser();
        $order = $this->purchaseAndPay($user);
        $intent = EnterpriseOrderIntent::where('order_id', $order->id)->first();
        $this->assertSame(EnterpriseOrderIntent::STATUS_PAID_WAITING_INVENTORY, $intent->status);
        $this->assertNull($user->fresh()->enterprise_kit_id);
    }

    /** E14: legacy in-place registration does not change the customer's subscription at all. */
    public function test_e14_legacy_registration_keeps_subscription_identical(): void
    {
        $legacyGroup = $this->makeGroup('存量企业组');
        $vip = $this->makeVipServer();
        // Manual provisioning (pre-system) already merged the legacy group
        // into the VIP server — registration must be a pure no-op merge.
        app(EnterpriseGroupMembershipService::class)->addGroupToServers($legacyGroup->id, [$vip->id]);

        $user = $this->makeUser([
            'group_id' => $legacyGroup->id,
            'plan_id' => $this->enterprisePlan->id,
            'expired_at' => time() + 86400 * 30,
            'transfer_enable' => 500 * 1073741824,
        ]);
        $s1 = $this->makePlainServer([$legacyGroup->id], ['name' => '存量主线', 'sort' => 321]);
        $s2 = $this->makePlainServer([$legacyGroup->id], ['name' => '存量备线', 'sort' => 322]);

        $before = $this->subscriptionServerIds($user);
        sort($before);

        $kit = app(EnterpriseAssignmentService::class)
            ->registerLegacy($user, $this->purpose, $legacyGroup->id, [$s1->id, $s2->id]);

        $after = $this->subscriptionServerIds($user->fresh());
        sort($after);
        $this->assertSame($before, $after, 'legacy registration changed the subscription');
        $this->assertSame(EnterpriseInventoryKit::STATUS_ASSIGNED_LEGACY, $kit->status);
        $this->assertSame($kit->id, (int) $user->fresh()->enterprise_kit_id);
    }
}
