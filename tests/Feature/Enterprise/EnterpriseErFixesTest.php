<?php

namespace Tests\Feature\Enterprise;

use App\Jobs\EnterpriseDeliveryVerifyJob;
use App\Models\EnterpriseInventoryAlert;
use App\Models\EnterpriseInventoryKit;
use App\Models\EnterpriseInventoryRule;
use App\Models\EnterpriseOrderIntent;
use App\Models\EnterprisePurpose;
use App\Models\MachineFulfillment;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\Enterprise\EnterpriseInventoryHealthService;
use App\Services\Enterprise\EnterpriseKitBuilderService;
use App\Services\Enterprise\EnterpriseLifecycleService;
use App\Services\OrderService;
use Illuminate\Support\Facades\Queue;

/**
 * SB-ER batch-3 regression coverage (enterprise side):
 *  ER-003 the waiting-queue drain completes the tier switch: when a
 *         queued kit is finally assigned, the customer's DELIVERED
 *         machine starts its grace window in the same flow;
 *  ER-006 a queue head whose user/order rows are gone is closed for
 *         manual handling instead of blocking the FIFO forever
 *         (residual left by SB-MONEY-008, which covered only the
 *         lapsed-subscription head);
 *  ER-007 the assignment freshness gate is real: no silent fallback
 *         to a stale kit, the reserved candidate is re-checked at
 *         payment time, and failed probes never refresh the
 *         last-pass timestamp;
 *  ER-008 delivery verification advances one bounded probe per queue
 *         execution and re-dispatches the next attempt (the old
 *         in-job sleep ladder could never finish inside the 30s
 *         production worker timeout);
 *  ER-009 manual rule confirmation validates kit state and rule
 *         ownership inside one transaction.
 */
class EnterpriseErFixesTest extends EnterpriseTestCase
{
    private function purchaseAndPay(User $user, ?string $purposeCode = 'JP_TIKTOK'): Order
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

    // ------------------------------------------------------------- ER-003

    public function test_drain_assignment_supersedes_delivered_machine(): void
    {
        $machineGroup = $this->makeGroup('独享整机交付组');
        $user = $this->makeUser(['group_id' => $machineGroup->id]);
        $fulfillment = MachineFulfillment::create([
            'order_id' => 987101,
            'user_id' => $user->id,
            'plan_id' => $this->enterprisePlan->id,
            'region_code' => 'JP',
            'region_name' => '日本',
            'status' => MachineFulfillment::STATUS_DELIVERED,
            'group_id' => $machineGroup->id,
        ]);
        $user->machine_fulfillment_id = $fulfillment->id;
        $user->save();

        $order = $this->purchaseAndPay($user); // no stock → waiting
        $intent = EnterpriseOrderIntent::where('order_id', $order->id)->first();
        $this->assertSame(EnterpriseOrderIntent::STATUS_PAID_WAITING_INVENTORY, $intent->status);
        $this->assertSame(MachineFulfillment::STATUS_DELIVERED, $fulfillment->fresh()->status);

        $this->buildReadyKit();
        $assigned = app(EnterpriseLifecycleService::class)->drainWaitingIntents();

        $this->assertGreaterThanOrEqual(1, $assigned);
        $this->assertNotNull($user->fresh()->enterprise_kit_id, 'the queued kit was assigned by the drain');
        $f = $fulfillment->fresh();
        $this->assertSame(
            MachineFulfillment::STATUS_GRACE_HELD,
            $f->status,
            'ER-003: once the drained kit is usable, the old machine must start its grace window'
        );
        $this->assertNotNull($f->grace_until);
    }

    // ------------------------------------------------------------- ER-006

    public function test_orphaned_queue_head_closes_and_drain_continues(): void
    {
        $user = $this->makeUser();
        $order = $this->purchaseAndPay($user); // no stock → waiting
        $intent = EnterpriseOrderIntent::where('order_id', $order->id)->first();
        $this->assertSame(EnterpriseOrderIntent::STATUS_PAID_WAITING_INVENTORY, $intent->status);

        // An older queue head whose user and order rows no longer exist.
        $orphan = EnterpriseOrderIntent::create([
            'purpose_id' => $this->purpose->id,
            'user_id' => 999999,
            'order_id' => 999999,
            'status' => EnterpriseOrderIntent::STATUS_PAID_WAITING_INVENTORY,
            'waiting_since' => now()->subHours(2),
        ]);

        $this->buildReadyKit();
        $assigned = app(EnterpriseLifecycleService::class)->drainWaitingIntents();

        $this->assertGreaterThanOrEqual(1, $assigned, 'ER-006: the valid intent behind the orphan must still drain');
        $this->assertSame(EnterpriseOrderIntent::STATUS_FAILED, $orphan->fresh()->status, 'the orphan is closed for manual handling');
        $this->assertNotNull($user->fresh()->enterprise_kit_id);
    }

    // ------------------------------------------------------------- ER-007

    public function test_stale_ready_kit_is_not_assigned(): void
    {
        $kit = $this->buildReadyKit();
        // The kit's last PASSING probe ages past the 6h freshness leg.
        $kit->last_probe_ok_at = now()->subHours(7);
        $kit->save();

        $user = $this->makeUser();
        $order = $this->purchaseAndPay($user);

        $intent = EnterpriseOrderIntent::where('order_id', $order->id)->first();
        $this->assertSame(
            EnterpriseOrderIntent::STATUS_PAID_WAITING_INVENTORY,
            $intent->status,
            'ER-007: no fresh kit → the order queues instead of receiving the stale kit'
        );
        $this->assertNull($user->fresh()->enterprise_kit_id);
        $this->assertSame(EnterpriseInventoryKit::STATUS_READY, $kit->fresh()->status, 'the stale kit stays in stock, unassigned');
    }

    public function test_failed_probe_does_not_refresh_last_pass_timestamp(): void
    {
        config(['suzu_enterprise.health_verify_backoff_seconds' => [0, 0, 0]]);
        $kit = $this->buildReadyKit();
        $kit->last_probe_ok_at = now()->subHours(2);
        $kit->save();
        $okStamp = $kit->fresh()->last_probe_ok_at->toDateTimeString();

        $this->probe->healthy = false;
        $result = app(EnterpriseInventoryHealthService::class)->verifyKit($kit->fresh(), 'full');

        $this->assertFalse($result['ok']);
        $kit = $kit->fresh();
        $this->assertSame($okStamp, $kit->last_probe_ok_at->toDateTimeString(), 'ER-007: a failing probe must not stamp the last-pass clock');
        $this->assertGreaterThan(
            now()->subHours(2)->timestamp,
            $kit->last_probe_at->timestamp,
            'the attempt timestamp still advances (scheduling/display)'
        );
    }

    public function test_held_kit_that_went_stale_is_not_delivered_at_payment(): void
    {
        $kit = $this->buildReadyKit();
        $user = $this->makeUser();
        $order = OrderService::createFromRequest(
            $user,
            $this->enterprisePlan,
            Plan::PERIOD_MONTHLY,
            null,
            'JP_TIKTOK'
        );
        $intent = EnterpriseOrderIntent::where('order_id', $order->id)->first();
        $this->assertNotNull($intent);
        $this->assertSame($kit->id, (int) $intent->kit_id, 'precondition: checkout held the kit');
        $this->assertSame(EnterpriseInventoryKit::STATUS_HELD, $kit->fresh()->status);

        // Between hold and payment the kit loses its freshness.
        $kit->last_probe_ok_at = now()->subHours(7);
        $kit->save();

        (new OrderService($order))->paid('CB-' . $order->trade_no);

        $intent = $intent->fresh();
        $this->assertSame(
            EnterpriseOrderIntent::STATUS_PAID_WAITING_INVENTORY,
            $intent->status,
            'ER-007: a held kit that went stale must not be delivered at payment'
        );
        $this->assertNull($user->fresh()->enterprise_kit_id);
    }

    // ------------------------------------------------------------- ER-008

    public function test_delivery_verify_advances_one_attempt_per_execution(): void
    {
        config(['suzu_enterprise.delivery_verify_backoff_seconds' => [0, 0]]);
        // Fabricate the exact post-claim state (kit ASSIGNED to the
        // user, intent RESERVED) — the state the job is dispatched
        // into — then drive the job manually under a faked queue.
        // (Queue::fake around a real purchase is not an option:
        // paid() opens the order through dispatchSync, which the fake
        // would swallow.)
        $kit = $this->buildReadyKit();
        $user = $this->makeUser();
        $order = OrderService::createFromRequest(
            $user,
            $this->enterprisePlan,
            Plan::PERIOD_MONTHLY,
            null,
            'JP_TIKTOK'
        );
        $intent = EnterpriseOrderIntent::where('order_id', $order->id)->first();
        $kit->status = EnterpriseInventoryKit::STATUS_ASSIGNED;
        $kit->current_order_id = $order->id;
        $kit->save();
        $intent->status = EnterpriseOrderIntent::STATUS_RESERVED;
        $intent->save();
        $user->enterprise_kit_id = $kit->id;
        $user->group_id = $kit->stock_group_id;
        $user->save();

        Queue::fake();

        $health = app(EnterpriseInventoryHealthService::class);
        $this->probe->healthy = false;

        // Attempt 1: one probe, no alert yet, attempt 2 re-dispatched.
        (new EnterpriseDeliveryVerifyJob($kit->id, $intent->id, 1))->handle($health);
        Queue::assertPushed(EnterpriseDeliveryVerifyJob::class, fn($job) => $job->attempt === 2);
        $this->assertFalse(
            EnterpriseInventoryAlert::where('kit_id', $kit->id)
                ->where('alert_type', EnterpriseInventoryAlert::TYPE_DELIVERY_VERIFY_FAILED)->exists(),
            'no terminal alert before the ladder is exhausted'
        );

        // Final attempt (3 = backoff count + 1): alert, no more pushes.
        $pushesBefore = Queue::pushed(EnterpriseDeliveryVerifyJob::class)->count();
        (new EnterpriseDeliveryVerifyJob($kit->id, $intent->id, 3))->handle($health);
        $this->assertSame($pushesBefore, Queue::pushed(EnterpriseDeliveryVerifyJob::class)->count());
        $this->assertTrue(
            EnterpriseInventoryAlert::where('kit_id', $kit->id)
                ->where('alert_type', EnterpriseInventoryAlert::TYPE_DELIVERY_VERIFY_FAILED)->exists(),
            'ER-008: the final attempt raises the persistent alert'
        );
        $this->assertSame(EnterpriseOrderIntent::STATUS_RESERVED, $intent->fresh()->status, 'still fail-closed');
    }

    // ------------------------------------------------------------- ER-009

    private function buildingKit(): EnterpriseInventoryKit
    {
        $kit = app(EnterpriseKitBuilderService::class)->buildKit($this->purpose);
        $this->assertSame(EnterpriseInventoryKit::STATUS_BUILDING, $kit->status, 'fixture: no rule stock → kit waits BUILDING');
        return $kit;
    }

    public function test_attach_rules_rejects_other_purposes_rules(): void
    {
        $kit = $this->buildingKit();
        $other = EnterprisePurpose::where('code', 'HK_TIKTOK')->firstOrFail();
        $other->entry_provider = EnterprisePurpose::PROVIDER_CSMZZMB;
        $other->save();
        $pair = $this->registerRulePair($other);

        try {
            app(EnterpriseKitBuilderService::class)->attachRules($kit, [$pair[0]->id, $pair[1]->id]);
            $this->fail('cross-purpose rules must be refused');
        } catch (\RuntimeException) {
            // expected
        }
        foreach ($pair as $rule) {
            $this->assertSame(EnterpriseInventoryRule::STATUS_READY, $rule->fresh()->status, 'refused rules stay in stock');
            $this->assertNull($rule->fresh()->kit_id);
        }
        $this->assertSame(0, $kit->fresh()->nodes()->whereNotNull('server_id')->count());
    }

    public function test_attach_rules_rejects_mismatched_targets_without_claiming(): void
    {
        $kit = $this->buildingKit();
        $a = EnterpriseInventoryRule::create([
            'purpose_id' => $this->purpose->id,
            'provider' => $this->purpose->entry_provider,
            'public_entry_port' => 31901,
            'target_ip' => '203.0.113.10',
            'target_port' => 21901,
            'provider_rule_ref' => 'TEST-MISMATCH-A',
            'status' => EnterpriseInventoryRule::STATUS_READY,
        ]);
        $b = EnterpriseInventoryRule::create([
            'purpose_id' => $this->purpose->id,
            'provider' => $this->purpose->entry_provider,
            'public_entry_port' => 31902,
            'target_ip' => '203.0.113.11',
            'target_port' => 21902,
            'provider_rule_ref' => 'TEST-MISMATCH-B',
            'status' => EnterpriseInventoryRule::STATUS_READY,
        ]);

        try {
            app(EnterpriseKitBuilderService::class)->attachRules($kit, [$a->id, $b->id]);
            $this->fail('a pair landing on two different machines must be refused');
        } catch (\RuntimeException) {
            // expected
        }
        $this->assertSame(EnterpriseInventoryRule::STATUS_READY, $a->fresh()->status, 'ER-009: a failed confirm must not leave rules claimed');
        $this->assertSame(EnterpriseInventoryRule::STATUS_READY, $b->fresh()->status);
        $this->assertNull($a->fresh()->kit_id);
        $this->assertNull($b->fresh()->kit_id);
    }

    public function test_attach_rules_cannot_be_confirmed_twice(): void
    {
        $kit = $this->buildingKit();
        $pair = $this->registerRulePair();
        $builder = app(EnterpriseKitBuilderService::class);
        $builder->attachRules($kit, [$pair[0]->id, $pair[1]->id]);
        $this->assertSame(2, $kit->fresh()->nodes()->whereNotNull('server_id')->count());
        foreach ($pair as $rule) {
            $this->assertSame(EnterpriseInventoryRule::STATUS_IN_USE, $rule->fresh()->status);
        }

        $pair2 = $this->registerRulePair();
        try {
            $builder->attachRules($kit->fresh(), [$pair2[0]->id, $pair2[1]->id]);
            $this->fail('a second confirmation on a built kit must be refused');
        } catch (\RuntimeException) {
            // expected
        }
        foreach ($pair2 as $rule) {
            $this->assertSame(EnterpriseInventoryRule::STATUS_READY, $rule->fresh()->status);
        }
        $this->assertSame(2, $kit->fresh()->nodes()->whereNotNull('server_id')->count(), 'no duplicate nodes were materialized');
    }
}
