<?php

namespace Tests\Feature\Machine;

use App\Exceptions\ApiException;
use App\Models\EnterpriseInventoryAlert;
use App\Models\MachineFulfillment;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\Machine\MachineNodeProvisioner;
use Carbon\Carbon;

/**
 * Business plans batch 3: region-required checkout, the fulfilment state
 * machine, SLA timers, machine naming, the 3-line delivery, VIP merge,
 * renewal stability, expiry grace + reclaim, and tier exclusivity.
 */
class MachineFulfillmentFlowTest extends MachineTestCase
{
    /** Region is mandatory: no region → no payable order, no fulfilment. */
    public function test_order_without_region_is_rejected(): void
    {
        $user = $this->makeUser();
        try {
            $this->buyMachinePlan($user, null);
            $this->fail('expected ApiException for missing region');
        } catch (ApiException $e) {
            $this->assertStringContainsString('region', strtolower($e->getMessage()));
        }
        $this->assertSame(0, Order::count());
        $this->assertSame(0, MachineFulfillment::count());

        try {
            $this->buyMachinePlan($user, 'XX');
            $this->fail('expected ApiException for invalid region');
        } catch (ApiException $e) {
            $this->assertTrue(true);
        }
        $this->assertSame(0, MachineFulfillment::count());
    }

    /** Region is snapshotted onto the order and the fulfilment is born. */
    public function test_order_snapshots_region_and_creates_fulfillment(): void
    {
        $user = $this->makeUser();
        $order = $this->buyMachinePlan($user, 'hk');
        $this->assertSame('HK', $order->machine_region_code);
        $f = MachineFulfillment::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(MachineFulfillment::STATUS_WAITING_PAYMENT, $f->status);
        $this->assertSame('HK', $f->region_code);
        $this->assertSame('香港', $f->region_name);

        // Cancelling the unpaid order cancels the fulfilment with it.
        (new \App\Services\OrderService($order))->cancel();
        $this->assertSame(MachineFulfillment::STATUS_CANCELLED, $f->fresh()->status);
    }

    /** Payment starts the SLA clock: deadline = paid_at + 3h. */
    public function test_payment_starts_sla_clock(): void
    {
        $user = $this->makeUser();
        $order = $this->buyMachinePlan($user);
        $this->payOrder($order);
        $f = MachineFulfillment::where('order_id', $order->id)->firstOrFail();
        $this->assertSame(MachineFulfillment::STATUS_PAID_PENDING_PURCHASE, $f->status);
        $this->assertNotNull($f->paid_at);
        $this->assertSame(
            $f->paid_at->copy()->addHours(3)->toDateTimeString(),
            $f->delivery_deadline_at->toDateTimeString()
        );
    }

    /** Machine name format: {邮箱前缀}用户VPS {ip}, U{id} fallback. */
    public function test_machine_name_format_and_fallback(): void
    {
        $user = $this->makeUser(['email' => 'alice.wong@example.com']);
        $this->assertSame('alice.wong用户VPS 203.0.113.10', MachineNodeProvisioner::machineNameFor($user, '203.0.113.10'));

        $odd = $this->makeUser(['email' => '+++@example.com']);
        $this->assertSame('U' . $odd->id . '用户VPS 203.0.113.10', MachineNodeProvisioner::machineNameFor($odd, '203.0.113.10'));
    }

    /**
     * Full walk: purchase → backfill → install detected → verified →
     * DELIVERED with exactly 3 dedicated lines (Reality/SS1/SW1, distinct
     * ports, one IP) + the shared VIP line merged into the subscription.
     */
    public function test_full_delivery_walk(): void
    {
        $user = $this->makeUser(['email' => 'carol@example.com']);
        $f = $this->deliverFor($user);

        $this->assertSame(MachineFulfillment::STATUS_DELIVERED, $f->status);
        $this->assertNotNull($f->delivered_at);

        $machine = ServerMachine::findOrFail($f->machine_id);
        $this->assertSame('carol用户VPS 203.0.113.10', $machine->name);
        $this->assertNotEmpty($machine->token);
        $this->assertStringContainsString('ziruiya/xboard-node/v1.13/install.sh', MachineNodeProvisioner::installCommand($machine));

        $nodes = Server::whereIn('id', $f->nodeIds())->get();
        $this->assertCount(3, $nodes);
        $types = $nodes->map(fn(Server $s) => $s->type . '/' . ($s->protocol_settings['network'] ?? ''))->sort()->values()->all();
        $this->assertSame(['trojan/grpc', 'vless/tcp', 'vless/ws'], $types);
        $ports = $nodes->map(fn(Server $s) => (int) $s->server_port)->all();
        $this->assertCount(3, array_unique($ports), 'the three lines use three different ports');
        foreach ($nodes as $node) {
            $this->assertSame('203.0.113.10', $node->host);
            $this->assertSame([(string) $f->group_id], array_map('strval', $node->group_ids));
        }

        // Customer: group swapped to the dedicated group, sees 3 dedicated
        // lines + the shared VIP line; an outsider sees none of the three.
        $user = $user->fresh();
        $this->assertSame((int) $f->group_id, (int) $user->group_id);
        $this->assertSame((int) $f->id, (int) $user->machine_fulfillment_id);
        $visible = collect(\App\Services\ServerService::getAvailableServers($user))->map(fn($s) => (int) (is_array($s) ? $s['id'] : $s->id))->all();
        foreach ($f->nodeIds() as $id) {
            $this->assertContains($id, $visible);
        }
        $this->assertContains((int) $this->vipServer->id, $visible, 'VIP shared lines are granted alongside');

        $outsider = $this->makeUser(['group_id' => $this->plainGroup->id, 'expired_at' => time() + 86400, 'transfer_enable' => 1000000]);
        $outsiderVisible = collect(\App\Services\ServerService::getAvailableServers($outsider))->map(fn($s) => (int) (is_array($s) ? $s['id'] : $s->id))->all();
        $this->assertSame([], array_values(array_intersect($f->nodeIds(), $outsiderVisible)), 'dedicated lines are isolated');
    }

    /** Verification failure (probe down) parks the fulfilment, retry delivers. */
    public function test_verify_failure_and_retry(): void
    {
        $user = $this->makeUser();
        $order = $this->buyMachinePlan($user);
        $this->payOrder($order);
        $f = MachineFulfillment::where('order_id', $order->id)->firstOrFail();
        $this->service()->backfillIp($f, ['public_ip' => '203.0.113.11']);
        $machine = ServerMachine::findOrFail($f->fresh()->machine_id);
        $machine->last_seen_at = time();
        $machine->save();

        $this->probe->reachable = false;
        $this->service()->tick();
        $f = $f->fresh();
        $this->assertSame(MachineFulfillment::STATUS_VERIFY_FAILED, $f->status);
        $report = json_decode($f->verify_report, true);
        $this->assertFalse($report['tcp_reality']);
        $this->assertTrue($report['machine_online']);
        $this->assertNotSame((int) $f->group_id, (int) $user->fresh()->group_id, 'no group grant before verification passes');

        $this->probe->reachable = true;
        $this->service()->retryVerify($f);
        $this->assertSame(MachineFulfillment::STATUS_DELIVERED, $f->fresh()->status);
    }

    /** Pin a fulfilment's SLA clock to a fixed payment moment. */
    private function pinSlaClock(MachineFulfillment $f, string $paidAt): MachineFulfillment
    {
        $f->paid_at = Carbon::parse($paidAt);
        $f->delivery_deadline_at = Carbon::parse($paidAt)->addHours(3);
        $f->save();
        return $f->fresh();
    }

    /** SLA stages fire at T+90 / T+150 / T+180 exactly once each. */
    public function test_sla_stages(): void
    {
        $user = $this->makeUser();
        $order = $this->buyMachinePlan($user);
        $this->payOrder($order);
        $f = MachineFulfillment::where('order_id', $order->id)->firstOrFail();
        $f = $this->pinSlaClock($f, '2026-11-25 10:00:00');
        $this->assertSame('2026-11-25 13:00:00', $f->delivery_deadline_at->toDateTimeString());

        Carbon::setTestNow('2026-11-25 11:29:00');
        $this->service()->tick();
        $this->assertSame(0, (int) $f->fresh()->sla_stage);

        Carbon::setTestNow('2026-11-25 11:30:00');
        $this->service()->tick();
        $this->assertSame(1, (int) $f->fresh()->sla_stage);
        $this->assertTrue(EnterpriseInventoryAlert::where('alert_type', 'machine_sla_90')->exists());

        Carbon::setTestNow('2026-11-25 12:30:00');
        $this->service()->tick();
        $this->assertSame(2, (int) $f->fresh()->sla_stage);
        $this->assertTrue(EnterpriseInventoryAlert::where('alert_type', 'machine_sla_150')->exists());

        Carbon::setTestNow('2026-11-25 13:00:00');
        $this->service()->tick();
        $f = $f->fresh();
        $this->assertSame(3, (int) $f->sla_stage);
        $this->assertTrue($f->sla_overdue);
        $this->assertNotNull($f->overdue_notice_at);
        Carbon::setTestNow();
    }

    /** Overdue choice WAIT: +24h extension, then termination review (no refund path exists). */
    public function test_overdue_choice_wait_then_termination_review(): void
    {
        $user = $this->makeUser();
        $order = $this->buyMachinePlan($user);
        $this->payOrder($order);
        $f = MachineFulfillment::where('order_id', $order->id)->firstOrFail();
        $this->pinSlaClock($f, '2026-11-25 10:00:00');

        Carbon::setTestNow('2026-11-25 13:05:00');
        $this->service()->tick();
        $this->assertTrue($f->fresh()->sla_overdue);

        $this->service()->recordUserChoice($f->fresh(), $user, MachineFulfillment::CHOICE_WAIT_ORIGINAL);
        $f = $f->fresh();
        $this->assertSame(MachineFulfillment::CHOICE_WAIT_ORIGINAL, $f->user_choice);
        $this->assertSame('2026-11-26 13:05:00', $f->extended_deadline_at->toDateTimeString());
        $this->assertFalse($f->termination_review);

        Carbon::setTestNow('2026-11-26 13:06:00');
        $this->service()->tick();
        $this->assertTrue($f->fresh()->termination_review);
        Carbon::setTestNow();
    }

    /** Overdue choice SWITCH: region changes on the fulfilment, SLA restarts, order snapshot untouched. */
    public function test_overdue_choice_switch_region(): void
    {
        $user = $this->makeUser();
        $order = $this->buyMachinePlan($user, 'JP');
        $this->payOrder($order);
        $f = MachineFulfillment::where('order_id', $order->id)->firstOrFail();
        $this->pinSlaClock($f, '2026-11-25 10:00:00');

        Carbon::setTestNow('2026-11-25 13:05:00');
        $this->service()->tick();
        $this->service()->recordUserChoice($f->fresh(), $user, MachineFulfillment::CHOICE_SWITCH_REGION, 'SG');
        $f = $f->fresh();
        $this->assertSame('SG', $f->region_code);
        $this->assertSame(MachineFulfillment::STATUS_PAID_PENDING_PURCHASE, $f->status);
        $this->assertFalse($f->sla_overdue);
        $this->assertSame('2026-11-25 16:05:00', $f->delivery_deadline_at->toDateTimeString());
        $this->assertSame('JP', $order->fresh()->machine_region_code, 'the order region snapshot never changes');
        Carbon::setTestNow();
    }

    /** Renewal keeps machine / nodes / group; the template group clobber is repaired. */
    public function test_renewal_keeps_machine_and_group(): void
    {
        $user = $this->makeUser();
        $f = $this->deliverFor($user);
        $machineId = $f->machine_id;
        $groupId = $f->group_id;

        $renewal = $this->buyMachinePlan($user->fresh(), null); // region inherited
        $this->assertSame($f->region_code, $renewal->machine_region_code);
        $this->payOrder($renewal);

        $this->assertSame(1, MachineFulfillment::count(), 'renewal must not create a second fulfilment');
        $f = $f->fresh();
        $this->assertSame(MachineFulfillment::STATUS_DELIVERED, $f->status);
        $this->assertSame($machineId, $f->machine_id);
        $this->assertSame($groupId, $f->group_id);
        $this->assertSame((int) $groupId, (int) $user->fresh()->group_id);
    }

    /** Expiry: entitlement stops at once, 48h retention, then the reclaim walk ends RECLAIMED. */
    public function test_expiry_grace_and_reclaim_walk(): void
    {
        $user = $this->makeUser();
        $f = $this->deliverFor($user);
        $machine = ServerMachine::findOrFail($f->machine_id);
        $oldToken = $machine->token;

        // Subscription lapses.
        $user->expired_at = time() - 60;
        $user->save();
        $this->service()->tick();
        $f = $f->fresh();
        $this->assertSame(MachineFulfillment::STATUS_GRACE_HELD, $f->status);
        $this->assertNotNull($f->grace_until);
        $this->assertSame((int) $this->machineTemplateGroup->id, (int) $user->fresh()->group_id, 'entitlement stopped immediately');
        $vip = Server::findOrFail($this->vipServer->id);
        $this->assertNotContains((string) $f->group_id, array_map('strval', $vip->group_ids), 'VIP grant removed at grace');

        // Still inside the 48h window: no reclaim yet.
        $this->service()->tick();
        $this->assertSame(MachineFulfillment::STATUS_GRACE_HELD, $f->fresh()->status);

        // Past the window: RECLAIM_PENDING with the first two steps auto-done.
        Carbon::setTestNow(now()->addHours(49));
        $this->service()->tick();
        $f = $f->fresh();
        $this->assertSame(MachineFulfillment::STATUS_RECLAIM_PENDING, $f->status);
        $checklist = $f->reclaim_checklist;
        $this->assertTrue($checklist['entitlement_stopped']['done']);
        $this->assertTrue($checklist['retention_elapsed']['done']);

        // Out-of-order step is refused.
        try {
            $this->service()->reclaimStep($f, 'vps_confirmed_stopped');
            $this->fail('expected out-of-order refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertTrue(true);
        }

        $this->service()->reclaimStep($f->fresh(), 'token_and_grants_revoked');
        $machine = $machine->fresh();
        $this->assertNotSame($oldToken, $machine->token, 'machine token revoked (rotated)');
        $this->assertFalse((bool) $machine->is_active);
        foreach (Server::whereIn('id', $f->nodeIds())->get() as $node) {
            $this->assertFalse((bool) $node->enabled);
            $this->assertFalse((bool) $node->show);
        }

        $this->service()->reclaimStep($f->fresh(), 'vps_confirmed_stopped', null, 'vendor instance destroyed');
        $this->service()->reclaimStep($f->fresh(), 'reclaim_recorded');
        $f = $f->fresh();
        $this->assertSame(MachineFulfillment::STATUS_RECLAIMED, $f->status);
        $this->assertNotNull($f->reclaimed_at);
        $this->assertNull($user->fresh()->machine_fulfillment_id);
        Carbon::setTestNow();
    }

    /** The deploy-time seed creates the real plan OFF-SALE with the finalised copy, idempotently. */
    public function test_seed_command_creates_offsale_plan(): void
    {
        $this->machinePlan->delete(); // drop the on-sale fixture; seed must create the real one
        $this->artisan('suzu:machine-plan-seed')->assertExitCode(0);
        $plan = Plan::where('name', '业务套餐-独享整机')->orderByDesc('id')->firstOrFail();
        $this->assertFalse((bool) $plan->show);
        $this->assertFalse((bool) $plan->sell);
        $this->assertSame('machine', $plan->business_tier);
        $this->assertTrue((bool) $plan->machine_region_required);
        $this->assertSame(1024, (int) $plan->transfer_enable);
        $this->assertSame(2, (int) $plan->device_limit);
        $this->assertSame(299, (int) $plan->prices[Plan::PERIOD_MONTHLY]);
        $this->assertStringContainsString('整台机器归您一人独享', $plan->content);
        $this->assertStringContainsString('不设退款', $plan->content);
        $this->assertStringNotContainsString('申请退款', $plan->content);

        $count = Plan::where('name', '业务套餐-独享整机')->count();
        $this->artisan('suzu:machine-plan-seed')->assertExitCode(0);
        $this->assertSame($count, Plan::where('name', '业务套餐-独享整机')->count(), 'seed is idempotent');
    }

    /**
     * Regression (v1.3.0 production find): the production container
     * binding MachineNodeProbe -> TcpMachineNodeProbe must autoload —
     * the implementation lives in its own PSR-4 file.
     */
    public function test_production_probe_binding_resolves(): void
    {
        $this->app->forgetInstance(\App\Services\Machine\MachineNodeProbe::class);
        $probe = app(\App\Services\Machine\MachineNodeProbe::class);
        $this->assertInstanceOf(\App\Services\Machine\TcpMachineNodeProbe::class, $probe);
        $this->assertInstanceOf(
            \App\Services\Machine\MachineFulfillmentService::class,
            app(\App\Services\Machine\MachineFulfillmentService::class)
        );
    }

    /** PURCHASE_BLOCKED is reachable and recoverable by the admin. */
    public function test_purchase_blocked_and_recovery(): void
    {
        $user = $this->makeUser();
        $order = $this->buyMachinePlan($user);
        $this->payOrder($order);
        $f = MachineFulfillment::where('order_id', $order->id)->firstOrFail();
        $this->service()->markBlocked($f, 'region sold out at vendor');
        $this->assertSame(MachineFulfillment::STATUS_PURCHASE_BLOCKED, $f->fresh()->status);
        $this->service()->startPurchase($f->fresh());
        $this->assertSame(MachineFulfillment::STATUS_PURCHASING, $f->fresh()->status);
    }
}
