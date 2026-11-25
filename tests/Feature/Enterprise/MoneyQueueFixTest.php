<?php

namespace Tests\Feature\Enterprise;

use App\Models\EnterpriseInventoryKit;
use App\Models\EnterpriseOrderIntent;
use App\Models\MachineFulfillment;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\Enterprise\EnterpriseLifecycleService;
use App\Services\OrderService;

/**
 * SB-MONEY-007 / SB-MONEY-008 (money-path batch 1):
 *  - 007: switching tiers must not strip the customer's current
 *    entitlement while the new tier is not yet usable — a stock-out
 *    enterprise purchase keeps the delivered machine (no supersede,
 *    no group overwrite), and a machine purchase awaiting delivery
 *    keeps the working enterprise kit group.
 *  - 008: a paid enterprise order whose subscription lapsed while it
 *    sat at the head of the FIFO waiting queue is moved to manual
 *    handling instead of blocking every later order for that purpose.
 */
class MoneyQueueFixTest extends EnterpriseTestCase
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

    public function test_stockout_enterprise_purchase_keeps_machine_group_and_delivery(): void
    {
        $machineGroup = $this->makeGroup('独享整机交付组');
        $user = $this->makeUser(['group_id' => $machineGroup->id]);
        $fulfillment = MachineFulfillment::create([
            'order_id' => 987001,
            'user_id' => $user->id,
            'plan_id' => $this->enterprisePlan->id,
            'region_code' => 'JP',
            'region_name' => '日本',
            'status' => MachineFulfillment::STATUS_DELIVERED,
            'group_id' => $machineGroup->id,
        ]);
        $user->machine_fulfillment_id = $fulfillment->id;
        $user->save();

        $order = $this->purchaseAndPay($user); // no kits exist at all → queues

        $intent = EnterpriseOrderIntent::where('order_id', $order->id)->first();
        $this->assertSame(EnterpriseOrderIntent::STATUS_PAID_WAITING_INVENTORY, $intent->status);
        $this->assertSame(
            MachineFulfillment::STATUS_DELIVERED,
            $fulfillment->fresh()->status,
            'a queued enterprise purchase must not send the still-delivered machine into its reclaim grace window'
        );
        $this->assertSame(
            $machineGroup->id,
            (int) $user->fresh()->group_id,
            'the customer keeps the machine group until the enterprise tier is actually delivered'
        );
    }

    public function test_machine_purchase_awaiting_delivery_keeps_enterprise_kit_group(): void
    {
        $kit = $this->buildReadyKit();
        $this->registerRulePair();
        $user = $this->makeUser();
        $this->purchaseAndPay($user);
        $kitGroupId = (int) $user->fresh()->group_id;
        $this->assertSame((int) $kit->stock_group_id, $kitGroupId, 'precondition: kit group assigned');

        $machinePlan = Plan::create([
            'group_id' => $this->templateGroup->id,
            'transfer_enable' => 1000,
            'name' => '业务-独享整机',
            'speed_limit' => null,
            'show' => 1,
            'sort' => 0,
            'renew' => 1,
            'prices' => [Plan::PERIOD_MONTHLY => 299],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'capacity_limit' => null,
            'sell' => 1,
            'device_limit' => 2,
            'machine_region_required' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $order = OrderService::createFromRequest($user->fresh(), $machinePlan, Plan::PERIOD_MONTHLY, null, null, 'JP');
        (new OrderService($order))->paid('CB-' . $order->trade_no);

        $fulfillment = MachineFulfillment::where('order_id', $order->id)->first();
        $this->assertNotNull($fulfillment);
        $this->assertSame(MachineFulfillment::STATUS_PAID_PENDING_PURCHASE, $fulfillment->status);
        $this->assertSame(
            $kitGroupId,
            (int) $user->fresh()->group_id,
            'the customer keeps the working kit group while the machine awaits delivery'
        );
    }

    public function test_lapsed_head_of_queue_closes_and_later_intent_still_drains(): void
    {
        $userA = $this->makeUser();
        $orderA = $this->purchaseAndPay($userA); // no kits → waiting
        $intentA = EnterpriseOrderIntent::where('order_id', $orderA->id)->first();
        $this->assertSame(EnterpriseOrderIntent::STATUS_PAID_WAITING_INVENTORY, $intentA->status);

        $userB = $this->makeUser();
        $orderB = $this->purchaseAndPay($userB);
        $intentB = EnterpriseOrderIntent::where('order_id', $orderB->id)->first();
        $this->assertSame(EnterpriseOrderIntent::STATUS_PAID_WAITING_INVENTORY, $intentB->status);

        // FIFO order: A queued before B.
        $intentA->waiting_since = now()->subHours(3);
        $intentA->save();
        $intentB->waiting_since = now()->subHours(2);
        $intentB->save();

        // A's subscription lapses while it sits at the head of the queue.
        $userA->expired_at = time() - 3600;
        $userA->save();

        $kit = $this->buildReadyKit();
        $drained = app(EnterpriseLifecycleService::class)->drainWaitingIntents();

        $this->assertSame(
            EnterpriseOrderIntent::STATUS_FAILED,
            $intentA->fresh()->status,
            'the lapsed head intent must move to manual handling instead of blocking the queue'
        );
        $this->assertSame(1, $drained, 'the later, still-valid intent must be served in the same drain run');
        $this->assertSame(EnterpriseInventoryKit::STATUS_ASSIGNED, $kit->fresh()->status);
        $this->assertSame($userB->id, (int) $kit->fresh()->current_user_id);
    }
}
