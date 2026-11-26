<?php

namespace Tests\Feature\Payment;

use App\Console\Commands\CheckCommission;
use App\Exceptions\ApiException;
use App\Http\Controllers\V1\Guest\PaymentController;
use App\Http\Controllers\V1\User\OrderController as UserOrderController;
use App\Http\Controllers\V1\User\TicketController;
use App\Http\Controllers\V1\User\UserController as UserAccountController;
use App\Http\Controllers\V2\Admin\CommissionWithdrawalController;
use App\Http\Controllers\V2\Admin\OrderController as AdminOrderController;
use App\Http\Requests\Admin\OrderAssign;
use App\Http\Requests\User\TicketWithdraw;
use App\Http\Requests\User\UserTransfer;
use App\Models\CommissionLog;
use App\Models\CommissionWithdrawal;
use App\Models\Coupon;
use App\Models\EnterpriseOrderIntent;
use App\Models\GiftCardCode;
use App\Models\GiftCardTemplate;
use App\Models\GiftCardUsage;
use App\Models\MachineFulfillment;
use App\Models\Order;
use App\Models\OrderPaymentAttempt;
use App\Models\OrderReceipt;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Plugin;
use App\Models\TicketMessage;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\GiftCardService;
use App\Services\OrderService;
use App\Services\TrafficResetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * P2/OPT sweep batch 1 (money path), one regression per finding:
 *  - SB-MONEY-009: a failed open-event reset must not leave a
 *    COMPLETED order (the event now runs inside the open transaction).
 *  - SB-MONEY-010: cancelling an order releases the coupon's global
 *    limit_use it consumed at creation.
 *  - SB-MONEY-011: plan prices convert to cents decimal-exactly
 *    (19.99 -> 1999, not 1998) and discounts are integer cents capped
 *    at the order total.
 *  - SB-MONEY-012: admin-assigned business orders get the same
 *    fulfilment initialization as user checkout.
 *  - SB-MONEY-013: the gift-card festival bonus only scales quantity
 *    rewards (plan_id / invite_reward_rate untouched), and an
 *    undeliverable plan reward aborts the redemption unconsumed.
 *  - SB-MONEY-014: sell=off blocks new purchases; pending orders
 *    occupy plan capacity.
 *  - SB-MONEY-015: callbacks reconcile against recorded checkout
 *    attempts; a second receipt on a paid order is recorded, not
 *    silently acknowledged.
 *  - SB-MONEY-016: withdrawal requests freeze their amount; frozen
 *    commission cannot be transferred; settle/reject are one-shot.
 *  - SB-MONEY-005 (index hardening): a re-queued payout does not pay
 *    twice — per-(order, level) rows are the book of record.
 */
class MoneyP2FixTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\DB::purge();
        \Illuminate\Support\Facades\DB::disconnect();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.stores.redis', ['driver' => 'array']);
        \Illuminate\Support\Facades\Cache::forgetDriver('redis');
        app()->forgetInstance(\App\Support\Setting::class);
    }

    // ---------------- fixtures ----------------

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => 'p2-' . uniqid() . '@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-000000000' . random_int(100, 999),
            'token' => bin2hex(random_bytes(16)),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'expired_at' => 0,
            'remind_expire' => 1,
            'remind_traffic' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function makePlan(array $overrides = []): Plan
    {
        return Plan::create(array_merge([
            'group_id' => null,
            'transfer_enable' => 200,
            'name' => 'P2 Plan',
            'speed_limit' => null,
            'show' => 1,
            'sort' => 0,
            'renew' => 1,
            'prices' => [Plan::PERIOD_MONTHLY => 29, Plan::PERIOD_QUARTERLY => 79],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'capacity_limit' => null,
            'sell' => 1,
            'device_limit' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function makeOrder(User $user, Plan $plan, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => 'P2' . uniqid(),
            'total_amount' => 2900,
            'balance_amount' => 0,
            'status' => Order::STATUS_PENDING,
            'commission_status' => 0,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    // ---------------- SB-MONEY-011 ----------------

    public function test_plan_price_converts_decimal_exactly(): void
    {
        $plan = $this->makePlan(['prices' => [Plan::PERIOD_MONTHLY => 19.99]]);
        $user = $this->makeUser();

        $order = OrderService::createFromRequest($user, $plan, Plan::PERIOD_MONTHLY);

        // (int)(19.99 * 100) === 1998 on the pre-fix code.
        $this->assertSame(1999, (int) $order->total_amount);
    }

    public function test_percentage_coupon_is_integer_cents(): void
    {
        $plan = $this->makePlan(['prices' => [Plan::PERIOD_MONTHLY => 19.99]]);
        $coupon = Coupon::create([
            'code' => 'P2HALF',
            'name' => 'half',
            'type' => 2,
            'value' => 50,
            'show' => 1,
            'started_at' => time() - 100,
            'ended_at' => time() + 86400,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user = $this->makeUser();

        $order = OrderService::createFromRequest($user, $plan, Plan::PERIOD_MONTHLY, $coupon->code);

        // 50% of 1999 = 999 (floored integer cents), not 999.5.
        $this->assertSame(999, (int) $order->discount_amount);
        $this->assertSame(1000, (int) $order->total_amount);
    }

    public function test_stacked_discounts_cannot_drive_total_negative(): void
    {
        $plan = $this->makePlan(['prices' => [Plan::PERIOD_MONTHLY => 19.99]]);
        $coupon = Coupon::create([
            'code' => 'P2HALF2',
            'name' => 'half',
            'type' => 2,
            'value' => 50,
            'show' => 1,
            'started_at' => time() - 100,
            'ended_at' => time() + 86400,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        // 50% coupon (999) + 60% VIP discount (1199) = 2198 > 1999.
        $user = $this->makeUser(['discount' => 60]);

        $order = OrderService::createFromRequest($user, $plan, Plan::PERIOD_MONTHLY, $coupon->code);

        $this->assertSame(1999, (int) $order->discount_amount);
        $this->assertSame(0, (int) $order->total_amount);
    }

    // ---------------- SB-MONEY-010 ----------------

    public function test_cancel_releases_coupon_global_quota(): void
    {
        $plan = $this->makePlan();
        $coupon = Coupon::create([
            'code' => 'P2ONCE',
            'name' => 'once',
            'type' => 1,
            'value' => 100,
            'limit_use' => 1,
            'show' => 1,
            'started_at' => time() - 100,
            'ended_at' => time() + 86400,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user = $this->makeUser();

        $order = OrderService::createFromRequest($user, $plan, Plan::PERIOD_MONTHLY, $coupon->code);
        $this->assertSame(0, (int) $coupon->fresh()->limit_use);

        $service = new OrderService($order);
        $this->assertTrue($service->cancel());
        $this->assertSame(1, (int) $coupon->fresh()->limit_use);

        // The released unit is actually usable again.
        $user2 = $this->makeUser();
        $order2 = OrderService::createFromRequest($user2, $plan, Plan::PERIOD_MONTHLY, $coupon->code);
        $this->assertSame($coupon->id, (int) $order2->coupon_id);
    }

    // ---------------- SB-MONEY-009 ----------------

    public function test_failed_open_event_reset_keeps_order_processing(): void
    {
        admin_setting(['renew_order_event_id' => 1]);
        $failingReset = new class extends TrafficResetService {
            public function performReset(User $user, string $triggerSource = TrafficResetLog::SOURCE_MANUAL, bool $onlyIfDue = false): bool
            {
                return false;
            }
        };
        app()->instance(TrafficResetService::class, $failingReset);

        $plan = $this->makePlan();
        $user = $this->makeUser([
            'plan_id' => $plan->id,
            'expired_at' => time() + 86400,
            'transfer_enable' => 200 * 1073741824,
        ]);
        $order = $this->makeOrder($user, $plan, [
            'type' => Order::TYPE_RENEWAL,
            'status' => Order::STATUS_PROCESSING,
        ]);

        try {
            (new OrderService($order))->open();
            $this->fail('open() must surface the failed event reset');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('流量重置失败', $e->getMessage());
        }

        // Pre-fix the order was already COMPLETED when the event ran.
        $this->assertSame(Order::STATUS_PROCESSING, (int) $order->fresh()->status);
    }

    public function test_open_event_reset_success_completes_order(): void
    {
        admin_setting(['renew_order_event_id' => 1]);
        $plan = $this->makePlan();
        $user = $this->makeUser([
            'plan_id' => $plan->id,
            'expired_at' => time() + 86400,
            'transfer_enable' => 200 * 1073741824,
        ]);
        $order = $this->makeOrder($user, $plan, [
            'type' => Order::TYPE_RENEWAL,
            'status' => Order::STATUS_PROCESSING,
        ]);

        (new OrderService($order))->open();

        $this->assertSame(Order::STATUS_COMPLETED, (int) $order->fresh()->status);
        $this->assertTrue(
            TrafficResetLog::where('user_id', $user->id)
                ->where('trigger_source', TrafficResetLog::SOURCE_ORDER)
                ->exists()
        );
    }

    // ---------------- SB-MONEY-014 ----------------

    public function test_sell_off_plan_cannot_be_ordered_by_new_buyer(): void
    {
        $plan = $this->makePlan(['sell' => 0]);
        $user = $this->makeUser();

        try {
            OrderService::createFromRequest($user, $plan, Plan::PERIOD_MONTHLY);
            $this->fail('sell=off plan must not be orderable');
        } catch (ApiException $e) {
            $this->assertSame(0, Order::where('user_id', $user->id)->count());
        }
    }

    public function test_pending_order_occupies_capacity(): void
    {
        $plan = $this->makePlan(['capacity_limit' => 1]);
        $user1 = $this->makeUser();
        $user2 = $this->makeUser();

        OrderService::createFromRequest($user1, $plan, Plan::PERIOD_MONTHLY);

        try {
            OrderService::createFromRequest($user2, $plan, Plan::PERIOD_MONTHLY);
            $this->fail('the last seat is held by the pending order');
        } catch (ApiException $e) {
            $this->assertSame(1, Order::where('plan_id', $plan->id)->count());
        }
    }

    public function test_same_plan_renewal_not_blocked_by_capacity(): void
    {
        $plan = $this->makePlan(['capacity_limit' => 1]);
        $user = $this->makeUser([
            'plan_id' => $plan->id,
            'expired_at' => time() + 86400,
        ]);

        $order = OrderService::createFromRequest($user, $plan, Plan::PERIOD_MONTHLY);
        $this->assertSame(Order::TYPE_RENEWAL, (int) $order->type);
    }

    // ---------------- SB-MONEY-012 ----------------

    private function assignViaAdmin(User $user, Plan $plan, array $extra = []): mixed
    {
        $request = OrderAssign::createFrom(Request::create('/admin/order/assign', 'POST', array_merge([
            'plan_id' => $plan->id,
            'email' => $user->email,
            'total_amount' => 9900,
            'period' => 'month_price',
        ], $extra)));
        $request->setContainer(app());
        return (new AdminOrderController())->assign($request);
    }

    public function test_admin_assign_enterprise_order_requires_and_records_purpose(): void
    {
        $plan = $this->makePlan(['enterprise_purpose_required' => 1]);
        $user = $this->makeUser();

        // Without the purpose the assignment must fail and create nothing.
        $this->assignViaAdmin($user, $plan);
        $this->assertSame(0, Order::where('user_id', $user->id)->count());

        // With it, the order carries the purpose and the fulfilment
        // intent exists — the same records user checkout creates.
        $this->assignViaAdmin($user, $plan, ['enterprise_purpose' => 'JP_TIKTOK']);
        $order = Order::where('user_id', $user->id)->first();
        $this->assertNotNull($order);
        $this->assertNotEmpty($order->enterprise_purpose_code);
        $this->assertTrue(EnterpriseOrderIntent::where('order_id', $order->id)->exists());
    }

    public function test_admin_assign_machine_order_creates_fulfilment(): void
    {
        $plan = $this->makePlan(['machine_region_required' => 1]);
        $user = $this->makeUser();

        $this->assignViaAdmin($user, $plan);
        $this->assertSame(0, Order::where('user_id', $user->id)->count());

        $this->assignViaAdmin($user, $plan, ['machine_region' => 'JP']);
        $order = Order::where('user_id', $user->id)->first();
        $this->assertNotNull($order);
        $this->assertSame('JP', $order->machine_region_code);
        $fulfilment = MachineFulfillment::where('order_id', $order->id)->first();
        $this->assertNotNull($fulfilment);
        $this->assertSame(MachineFulfillment::STATUS_WAITING_PAYMENT, $fulfilment->status);
    }

    // ---------------- SB-MONEY-013 ----------------

    public function test_festival_bonus_scales_quantities_only(): void
    {
        $template = GiftCardTemplate::create([
            'admin_id' => 1,
            'name' => 'P2 Festival',
            'type' => GiftCardTemplate::TYPE_GENERAL,
            'status' => 1,
            'rewards' => [
                'balance' => 1000,
                'expire_days' => 10,
                'plan_id' => 7,
                'invite_reward_rate' => 0.2,
            ],
            'special_config' => [
                'festival_bonus' => 2.0,
                'start_time' => time() - 100,
                'end_time' => time() + 100,
            ],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user = $this->makeUser();

        $rewards = $template->calculateActualRewards($user);

        $this->assertSame(2000, $rewards['balance']);
        $this->assertSame(20, $rewards['expire_days']);
        // Pre-fix both of these were destroyed by the blanket multiply:
        // plan_id 7 became 14, and rate 0.2 was int-truncated to 0.
        $this->assertSame(7, $rewards['plan_id']);
        $this->assertSame(0.2, $rewards['invite_reward_rate']);
    }

    public function test_undeliverable_plan_reward_aborts_redemption_unconsumed(): void
    {
        $template = GiftCardTemplate::create([
            'admin_id' => 1,
            'name' => 'P2 Broken Plan',
            'type' => GiftCardTemplate::TYPE_PLAN,
            'status' => 1,
            'rewards' => ['plan_id' => 999999, 'plan_validity_days' => 30],
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $code = GiftCardCode::create([
            'template_id' => $template->id,
            'code' => 'P2BROKENCODE',
            'status' => GiftCardCode::STATUS_UNUSED,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user = $this->makeUser();

        try {
            (new GiftCardService('P2BROKENCODE'))->setUser($user)->redeem();
            $this->fail('an undeliverable plan reward must abort the redemption');
        } catch (ApiException $e) {
            $this->assertSame(GiftCardCode::STATUS_UNUSED, (int) $code->fresh()->status);
            $this->assertSame(0, GiftCardUsage::where('code_id', $code->id)->count());
        }
    }

    // ---------------- SB-MONEY-016 ----------------

    private function withdrawViaController(User $user): mixed
    {
        $request = TicketWithdraw::createFrom(Request::create('/ticket/withdraw', 'POST', [
            'withdraw_method' => 'USDT',
            'withdraw_account' => 'TExampleAccount',
        ]));
        $request->setUserResolver(fn() => $user);
        $request->setContainer(app());
        return (new TicketController())->withdraw($request);
    }

    private function transferViaController(User $user, int $amount): mixed
    {
        $request = UserTransfer::createFrom(Request::create('/user/transfer', 'POST', [
            'transfer_amount' => $amount,
        ]));
        $request->setUserResolver(fn() => $user);
        $request->setContainer(app());
        return app(UserAccountController::class)->transfer($request);
    }

    public function test_withdrawal_freezes_amount_and_blocks_double_spend(): void
    {
        $user = $this->makeUser(['commission_balance' => 20000]);

        $this->withdrawViaController($user);

        $withdrawal = CommissionWithdrawal::where('user_id', $user->id)->first();
        $this->assertNotNull($withdrawal);
        $this->assertSame(CommissionWithdrawal::STATUS_PENDING, $withdrawal->status);
        $this->assertSame(20000, (int) $withdrawal->amount_cents);
        $this->assertNotNull($withdrawal->ticket_id);
        // The ticket quotes the frozen amount and the reference.
        $message = TicketMessage::where('ticket_id', $withdrawal->ticket_id)->first();
        $this->assertNotNull($message);
        $this->assertStringContainsString($withdrawal->reference, (string) $message->message);
        $this->assertStringContainsString('200.00', (string) $message->message);

        // A second request while one is pending creates nothing.
        $this->withdrawViaController($user->fresh());
        $this->assertSame(1, CommissionWithdrawal::where('user_id', $user->id)->count());

        // The frozen commission cannot be transferred into balance.
        $this->transferViaController($user->fresh(), 20000);
        $this->assertSame(0, (int) $user->fresh()->balance);
        $this->assertSame(20000, (int) $user->fresh()->commission_balance);

        // Rejection releases the freeze; the transfer then succeeds.
        (new CommissionWithdrawalController())->reject(Request::create('/', 'POST', [
            'reference' => $withdrawal->reference,
        ]));
        $this->assertSame(CommissionWithdrawal::STATUS_REJECTED, $withdrawal->fresh()->status);
        $this->transferViaController($user->fresh(), 20000);
        $this->assertSame(20000, (int) $user->fresh()->balance);
        $this->assertSame(0, (int) $user->fresh()->commission_balance);
    }

    public function test_withdrawal_settle_deducts_frozen_amount_once(): void
    {
        $user = $this->makeUser(['commission_balance' => 15000]);
        $this->withdrawViaController($user);
        $withdrawal = CommissionWithdrawal::where('user_id', $user->id)->firstOrFail();

        $controller = new CommissionWithdrawalController();
        $controller->settle(Request::create('/', 'POST', ['reference' => $withdrawal->reference]));
        $this->assertSame(CommissionWithdrawal::STATUS_PAID, $withdrawal->fresh()->status);
        $this->assertSame(0, (int) $user->fresh()->commission_balance);

        // One-shot: a second settle changes nothing.
        $controller->settle(Request::create('/', 'POST', ['reference' => $withdrawal->reference]));
        $this->assertSame(0, (int) $user->fresh()->commission_balance);
    }

    // ---------------- SB-MONEY-005 (index hardening) ----------------

    public function test_requeued_payout_does_not_pay_twice(): void
    {
        $inviter = $this->makeUser(['commission_balance' => 0]);
        $buyer = $this->makeUser();
        $plan = $this->makePlan();
        $order = $this->makeOrder($buyer, $plan, [
            'status' => Order::STATUS_COMPLETED,
            'commission_status' => 1,
            'commission_balance' => 1000,
            'invite_user_id' => $inviter->id,
        ]);

        $command = new CheckCommission();
        $command->autoPayCommission();

        $this->assertSame(1000, (int) $inviter->fresh()->commission_balance);
        $logs = CommissionLog::where('trade_no', $order->trade_no)->get();
        $this->assertCount(1, $logs);
        $this->assertSame(0, (int) $logs[0]->level);

        // An admin re-queues the order (the batch-1 guard allows
        // 1 -> 1 on a completed order); the payout must not run the
        // money a second time — the (trade_no, level) row is the
        // book of record.
        $order->fresh()->update(['commission_status' => 1]);
        $command->autoPayCommission();

        $this->assertSame(1000, (int) $inviter->fresh()->commission_balance);
        $this->assertSame(1, CommissionLog::where('trade_no', $order->trade_no)->count());
        $this->assertSame(2, (int) $order->fresh()->commission_status);
    }

    // ---------------- SB-MONEY-015 ----------------

    private function callHandle(Order $order, string $callbackNo, array $verify, ?int $paymentId = null): bool
    {
        $controller = new PaymentController();
        $method = new \ReflectionMethod($controller, 'handle');
        $method->setAccessible(true);
        return $method->invoke($controller, $order->trade_no, $callbackNo, $verify, $paymentId);
    }

    public function test_callback_reconciles_against_earlier_attempt(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();
        $paymentA = Payment::create([
            'uuid' => 'p2attempt-a', 'payment' => 'EPay', 'name' => 'A', 'enable' => 1,
            'config' => '{}',
            'handling_fee_fixed' => 100, 'created_at' => time(), 'updated_at' => time(),
        ]);
        $paymentB = Payment::create([
            'uuid' => 'p2attempt-b', 'payment' => 'EPay', 'name' => 'B', 'enable' => 1,
            'config' => '{}',
            'handling_fee_fixed' => 200, 'created_at' => time(), 'updated_at' => time(),
        ]);
        // Latest checkout was B (quote 3100, persisted on the order);
        // attempt A quoted 3000 and its link is what got paid.
        $order = $this->makeOrder($user, $plan, [
            'total_amount' => 2900,
            'handling_amount' => 200,
            'payment_id' => $paymentB->id,
        ]);
        OrderPaymentAttempt::create([
            'order_id' => $order->id, 'payment_id' => $paymentA->id,
            'handling_amount' => 100, 'payable_cents' => 3000,
        ]);
        OrderPaymentAttempt::create([
            'order_id' => $order->id, 'payment_id' => $paymentB->id,
            'handling_amount' => 200, 'payable_cents' => 3100,
        ]);

        $ok = $this->callHandle($order, 'TXN-ATTEMPT-A', [
            'paid_amount_cents' => 3000,
            'paid_currency' => 'CNY',
        ], $paymentA->id);

        // Pre-fix this returned false: 3000 !== the order's current
        // quote 3100, so a real payment was rejected.
        $this->assertTrue($ok);
        $this->assertNotSame(Order::STATUS_PENDING, (int) $order->fresh()->status);
        $attemptA = OrderPaymentAttempt::where('order_id', $order->id)
            ->where('payment_id', $paymentA->id)->first();
        $this->assertSame('TXN-ATTEMPT-A', $attemptA->callback_no);
    }

    public function test_second_receipt_on_paid_order_is_recorded(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();
        $order = $this->makeOrder($user, $plan, [
            'status' => Order::STATUS_COMPLETED,
            'callback_no' => 'TXN-FIRST',
            'paid_at' => time(),
        ]);

        // Same transaction re-notified: idempotent ack, no record.
        $this->assertTrue($this->callHandle($order, 'TXN-FIRST', [
            'paid_amount_cents' => 2900, 'paid_currency' => 'CNY',
        ]));
        $this->assertSame(0, OrderReceipt::count());

        // A different transaction on the paid order: acknowledged AND
        // recorded (pre-fix it was acknowledged with no trace at all).
        $this->assertTrue($this->callHandle($order, 'TXN-SECOND', [
            'paid_amount_cents' => 2900, 'paid_currency' => 'CNY',
        ]));
        $receipt = OrderReceipt::where('callback_no', 'TXN-SECOND')->first();
        $this->assertNotNull($receipt);
        $this->assertSame($order->id, (int) $receipt->order_id);
        $this->assertSame(2900, (int) $receipt->paid_amount_cents);

        // Its re-notification stays a single record.
        $this->assertTrue($this->callHandle($order, 'TXN-SECOND', [
            'paid_amount_cents' => 2900, 'paid_currency' => 'CNY',
        ]));
        $this->assertSame(1, OrderReceipt::count());

        // No verifiable evidence: not acknowledged, nothing recorded.
        $this->assertFalse($this->callHandle($order, 'TXN-THIRD', []));
        $this->assertSame(1, OrderReceipt::count());
    }

    public function test_checkout_records_payment_attempt(): void
    {
        Plugin::create([
            'name' => 'EPay',
            'code' => 'epay',
            'version' => '1.0.0',
            'is_enabled' => true,
            'type' => Plugin::TYPE_PAYMENT,
        ]);
        // Production boots enabled plugins via the service provider;
        // in tests the provider ran before this row existed, so run
        // the same initialization explicitly (it registers the
        // available_payment_methods filter PaymentService checks).
        $manager = app(\App\Services\Plugin\PluginManager::class);
        $initialized = new \ReflectionProperty($manager, 'pluginsInitialized');
        $initialized->setAccessible(true);
        $initialized->setValue($manager, false);
        $manager->initializeEnabledPlugins();
        $payment = Payment::create([
            'uuid' => 'p2checkout00000000000000000001',
            'payment' => 'EPay',
            'name' => '易支付测试',
            'enable' => 1,
            'handling_fee_fixed' => 100,
            'config' => json_encode([
                'url' => 'https://pay.example.com',
                'pid' => '1001',
                'key' => 'epay-secret',
                'type' => 'alipay',
            ]),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
        $user = $this->makeUser();
        $plan = $this->makePlan();
        $order = $this->makeOrder($user, $plan, ['total_amount' => 2900]);

        $request = Request::create('/order/checkout', 'POST', [
            'trade_no' => $order->trade_no,
            'method' => $payment->id,
        ]);
        $request->setUserResolver(fn() => $user);
        (new UserOrderController())->checkout($request);

        $attempt = OrderPaymentAttempt::where('order_id', $order->id)->first();
        $this->assertNotNull($attempt);
        $this->assertSame($payment->id, (int) $attempt->payment_id);
        $this->assertSame(100, (int) $attempt->handling_amount);
        $this->assertSame(3000, (int) $attempt->payable_cents);
    }
}
