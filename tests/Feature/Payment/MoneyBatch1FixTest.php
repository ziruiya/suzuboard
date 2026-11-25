<?php

namespace Tests\Feature\Payment;

use App\Http\Controllers\V1\Guest\PaymentController;
use App\Http\Controllers\V2\Admin\OrderController as AdminOrderController;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Plugin;
use App\Models\User;
use App\Services\OrderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * SB-MONEY fix batch 1 (panel money path, P1):
 *  - 001: gateway callbacks reconcile against the checkout quote
 *    (order total + persisted handling fee), not the bare total.
 *  - 002: a verified receipt arriving after cancellation is recorded
 *    on the order instead of being acknowledged and lost.
 *  - 004: manual admin confirmations get per-order callback numbers
 *    so the AUD-040 unique index no longer blocks the second one.
 *  - 006: upgrade surplus only counts the current continuous
 *    subscription segment, not fully lapsed historical orders.
 */
class MoneyBatch1FixTest extends TestCase
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
            'email' => 'money1@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-000000000401',
            'token' => '0123456789abcdef0123456789abcdef',
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
            'name' => 'Money Batch Plan',
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
            'trade_no' => 'MONEY1' . uniqid(),
            'total_amount' => 2900,
            'balance_amount' => 0,
            'status' => Order::STATUS_PENDING,
            'commission_status' => 0,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function makeEpayGateway(): Payment
    {
        Plugin::create([
            'name' => 'EPay',
            'code' => 'epay',
            'version' => '1.0.0',
            'is_enabled' => true,
            'type' => Plugin::TYPE_PAYMENT,
        ]);
        $manager = app(\App\Services\Plugin\PluginManager::class);
        $initialized = new \ReflectionProperty($manager, 'pluginsInitialized');
        $initialized->setAccessible(true);
        $initialized->setValue($manager, false);

        return Payment::create([
            'uuid' => 'money1uuid00000000000000000001',
            'payment' => 'EPay',
            'name' => '易支付测试',
            'enable' => 1,
            'config' => json_encode([
                'url' => 'https://pay.example.com',
                'pid' => '1001',
                'key' => 'epay-secret',
                'type' => 'alipay',
            ]),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function epayCallback(Order $order, string $money, string $txn = 'EPAY-TX-9'): array
    {
        $params = [
            'pid' => '1001',
            'type' => 'alipay',
            'out_trade_no' => $order->trade_no,
            'trade_no' => $txn,
            'name' => $order->trade_no,
            'money' => $money,
            'trade_status' => 'TRADE_SUCCESS',
            'sign_type' => 'MD5',
        ];
        $signParams = $params;
        unset($signParams['sign_type']);
        ksort($signParams);
        $params['sign'] = md5(stripslashes(urldecode(http_build_query($signParams))) . 'epay-secret');
        return $params;
    }

    // ---------------- SB-MONEY-001 ----------------

    public function test_callback_reporting_the_fee_inclusive_quote_completes_order(): void
    {
        $gateway = $this->makeEpayGateway();
        $user = $this->makeUser();
        $plan = $this->makePlan();
        // Checkout persisted a 100-cent handling fee; the gateway was
        // quoted 3000 and reports what it actually charged.
        $order = $this->makeOrder($user, $plan, ['handling_amount' => 100]);

        $response = $this->call('POST', "/api/v1/guest/payment/notify/EPay/{$gateway->uuid}", $this->epayCallback($order, '30.00'));

        $response->assertStatus(200);
        $this->assertSame('success', $response->getContent());
        $this->assertSame(Order::STATUS_COMPLETED, Order::findOrFail($order->id)->status);
    }

    public function test_callback_reporting_only_the_bare_total_is_rejected_when_a_fee_was_quoted(): void
    {
        $gateway = $this->makeEpayGateway();
        $user = $this->makeUser();
        $plan = $this->makePlan();
        $order = $this->makeOrder($user, $plan, ['handling_amount' => 100]);

        $response = $this->call('POST', "/api/v1/guest/payment/notify/EPay/{$gateway->uuid}", $this->epayCallback($order, '29.00'));

        $response->assertStatus(400);
        $this->assertSame(Order::STATUS_PENDING, Order::findOrFail($order->id)->status);
    }

    // ---------------- SB-MONEY-002 ----------------

    public function test_verified_receipt_after_cancellation_is_recorded_on_the_order(): void
    {
        $gateway = $this->makeEpayGateway();
        $user = $this->makeUser();
        $plan = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        $this->assertTrue((new OrderService($order))->cancel());
        $this->assertSame(Order::STATUS_CANCELLED, Order::findOrFail($order->id)->status);

        // The customer had already paid at the gateway; the success
        // callback arrives after the cancellation.
        $response = $this->call('POST', "/api/v1/guest/payment/notify/EPay/{$gateway->uuid}", $this->epayCallback($order, '29.00', 'EPAY-LATE-1'));

        $response->assertStatus(200);
        $fresh = Order::findOrFail($order->id);
        // The order stays cancelled (its balance was already refunded),
        // but the receipt is now in the books for manual handling.
        $this->assertSame(Order::STATUS_CANCELLED, $fresh->status);
        $this->assertSame('EPAY-LATE-1', $fresh->callback_no);
        $this->assertNotNull($fresh->paid_at);
    }

    public function test_unverifiable_receipt_after_cancellation_is_not_recorded(): void
    {
        $gateway = $this->makeEpayGateway();
        $user = $this->makeUser();
        $plan = $this->makePlan();
        $order = $this->makeOrder($user, $plan);
        $this->assertTrue((new OrderService($order))->cancel());

        $response = $this->call('POST', "/api/v1/guest/payment/notify/EPay/{$gateway->uuid}", $this->epayCallback($order, '1.00', 'EPAY-LATE-2'));

        $response->assertStatus(200);
        $fresh = Order::findOrFail($order->id);
        $this->assertSame(Order::STATUS_CANCELLED, $fresh->status);
        $this->assertNull($fresh->callback_no);
    }

    // --------- SB-MONEY-002 fix-up (GPT re-review A/B) ---------

    public function test_receipt_persistence_failure_fails_the_callback_acknowledgement(): void
    {
        $gateway = $this->makeEpayGateway();
        $user = $this->makeUser();
        $plan = $this->makePlan();
        // Another order already holds this gateway transaction number,
        // so persisting the receipt violates the AUD-040 unique index
        // on callback_no: the write genuinely fails in the database.
        $this->makeOrder($user, $plan, [
            'status' => Order::STATUS_COMPLETED,
            'callback_no' => 'EPAY-TX-CONFLICT',
        ]);
        $order = $this->makeOrder($user, $plan);
        $this->assertTrue((new OrderService($order))->cancel());

        $response = $this->call('POST', "/api/v1/guest/payment/notify/EPay/{$gateway->uuid}", $this->epayCallback($order, '29.00', 'EPAY-TX-CONFLICT'));

        // The gateway must NOT be told the payment was handled — it
        // has to keep retrying while the receipt is missing from the
        // books (previously the write exception was swallowed and the
        // callback was acknowledged as success).
        $response->assertStatus(400);
        $fresh = Order::findOrFail($order->id);
        $this->assertSame(Order::STATUS_CANCELLED, $fresh->status);
        $this->assertNull($fresh->callback_no);
        $this->assertNull($fresh->paid_at);
    }

    public function test_receipt_without_verifiable_amount_is_not_recorded_and_fails(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();
        $order = $this->makeOrder($user, $plan);
        $this->assertTrue((new OrderService($order))->cancel());

        $controller = new PaymentController();
        $method = new \ReflectionMethod($controller, 'handle');
        $method->setAccessible(true);

        // The signature verified, but the plugin reported no amount:
        // nothing proves money arrived, so nothing may be booked as
        // received and the callback must fail (gateway keeps retrying).
        $result = $method->invoke($controller, $order->trade_no, 'CB-NO-AMOUNT', [
            'paid_currency' => 'CNY',
        ]);

        $this->assertFalse($result);
        $fresh = Order::findOrFail($order->id);
        $this->assertSame(Order::STATUS_CANCELLED, $fresh->status);
        $this->assertNull($fresh->callback_no);
        $this->assertNull($fresh->paid_at);
    }

    public function test_receipt_without_verifiable_currency_is_not_recorded_and_fails(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();
        $order = $this->makeOrder($user, $plan);
        $this->assertTrue((new OrderService($order))->cancel());

        $controller = new PaymentController();
        $method = new \ReflectionMethod($controller, 'handle');
        $method->setAccessible(true);

        // Amount reported but no currency to reconcile it against:
        // equally unprovable as a receipt.
        $result = $method->invoke($controller, $order->trade_no, 'CB-NO-CURRENCY', [
            'paid_amount_cents' => 2900,
        ]);

        $this->assertFalse($result);
        $fresh = Order::findOrFail($order->id);
        $this->assertSame(Order::STATUS_CANCELLED, $fresh->status);
        $this->assertNull($fresh->callback_no);
        $this->assertNull($fresh->paid_at);
    }

    public function test_duplicate_receipt_notification_is_recorded_once_without_error(): void
    {
        $gateway = $this->makeEpayGateway();
        $user = $this->makeUser();
        $plan = $this->makePlan();
        $order = $this->makeOrder($user, $plan);
        $this->assertTrue((new OrderService($order))->cancel());

        $callback = $this->epayCallback($order, '29.00', 'EPAY-DUP-1');
        $first = $this->call('POST', "/api/v1/guest/payment/notify/EPay/{$gateway->uuid}", $callback);
        $first->assertStatus(200);
        $afterFirst = Order::findOrFail($order->id);
        $this->assertSame('EPAY-DUP-1', $afterFirst->callback_no);
        $this->assertNotNull($afterFirst->paid_at);

        // Gateways retry notifications: the identical callback must be
        // acknowledged again without error and without re-recording
        // (same callback number, original paid_at untouched).
        $second = $this->call('POST', "/api/v1/guest/payment/notify/EPay/{$gateway->uuid}", $callback);
        $second->assertStatus(200);
        $this->assertSame('success', $second->getContent());
        $afterSecond = Order::findOrFail($order->id);
        $this->assertSame(Order::STATUS_CANCELLED, $afterSecond->status);
        $this->assertSame('EPAY-DUP-1', $afterSecond->callback_no);
        $this->assertSame($afterFirst->paid_at, $afterSecond->paid_at);
    }

    // --------- SB-MONEY-002-C1 (GPT incremental re-review) ---------

    public function test_second_receipt_with_different_transaction_conflicts_and_fails(): void
    {
        $gateway = $this->makeEpayGateway();
        $user = $this->makeUser();
        $plan = $this->makePlan();
        $order = $this->makeOrder($user, $plan);
        $this->assertTrue((new OrderService($order))->cancel());

        // First late payment: recorded on the cancelled order.
        $first = $this->call('POST', "/api/v1/guest/payment/notify/EPay/{$gateway->uuid}", $this->epayCallback($order, '29.00', 'EPAY-C1-FIRST'));
        $first->assertStatus(200);
        $afterFirst = Order::findOrFail($order->id);
        $this->assertSame('EPAY-C1-FIRST', $afterFirst->callback_no);
        $this->assertNotNull($afterFirst->paid_at);

        // Collect log entries from here on so the conflict record
        // can be asserted directly.
        $logged = [];
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Log\Events\MessageLogged::class,
            function ($event) use (&$logged) {
                $logged[] = $event;
            }
        );

        // A second, DIFFERENT transaction for the same cancelled
        // order is another real payment, not a duplicate
        // notification: it must not be folded into the first receipt
        // and acknowledged as if it were the same money.
        $second = $this->call('POST', "/api/v1/guest/payment/notify/EPay/{$gateway->uuid}", $this->epayCallback($order, '29.00', 'EPAY-C1-SECOND'));
        $second->assertStatus(400);

        // The recorded receipt is untouched.
        $afterSecond = Order::findOrFail($order->id);
        $this->assertSame(Order::STATUS_CANCELLED, $afterSecond->status);
        $this->assertSame('EPAY-C1-FIRST', $afterSecond->callback_no);
        $this->assertSame($afterFirst->paid_at, $afterSecond->paid_at);

        // And the conflict is on record for manual reconciliation,
        // carrying both transaction numbers and the amount.
        $conflict = collect($logged)->first(fn ($event) => $event->level === 'error'
            && str_contains($event->message, 'conflicting callback_no'));
        $this->assertNotNull($conflict, 'expected an error log entry for the conflicting callback_no');
        $this->assertSame('EPAY-C1-FIRST', $conflict->context['recorded_callback_no'] ?? null);
        $this->assertSame('EPAY-C1-SECOND', $conflict->context['callback_no'] ?? null);
        $this->assertSame(2900, (int) ($conflict->context['paid_amount_cents'] ?? 0));
    }

    public function test_receipt_save_returning_false_fails_the_callback_acknowledgement(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();
        $order = $this->makeOrder($user, $plan);
        $this->assertTrue((new OrderService($order))->cancel());

        // Make the persistence step fail WITHOUT throwing: an
        // Eloquent `saving` listener veto makes Model::save() return
        // false — the exact non-exception failure mode C1 calls out.
        // (Registered only now, after the cancel above has saved.)
        Order::saving(function () {
            return false;
        });

        $controller = new PaymentController();
        $method = new \ReflectionMethod($controller, 'handle');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $order->trade_no, 'CB-SAVE-FALSE', [
            'paid_amount_cents' => 2900,
            'paid_currency' => 'CNY',
        ]);

        // save() reported failure, so the receipt is not on the
        // books and the gateway must not be told it was handled.
        $this->assertFalse($result);
        $fresh = Order::findOrFail($order->id);
        $this->assertSame(Order::STATUS_CANCELLED, $fresh->status);
        $this->assertNull($fresh->callback_no);
        $this->assertNull($fresh->paid_at);
    }

    // --------- SB-MONEY-002-C1-R1 (concurrency atomicity) ---------

    public function test_concurrent_second_receipt_decides_from_locked_reread_not_caller_snapshot(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();
        $order = $this->makeOrder($user, $plan);
        $this->assertTrue((new OrderService($order))->cancel());

        $controller = new PaymentController();
        $method = new \ReflectionMethod($controller, 'recordReceiptOnCancelledOrder');
        $method->setAccessible(true);
        $verify = ['paid_amount_cents' => 2900, 'paid_currency' => 'CNY'];

        // GPT's race timeline, reproduced at the function boundary:
        // handler TX-002 reads the order (callback_no still NULL)
        // BEFORE handler TX-001 commits. This stale snapshot is
        // exactly the in-memory state the losing callback carries
        // into recordReceiptOnCancelledOrder() in production, where
        // handle() reads the row and passes the model along.
        $staleSnapshot = Order::findOrFail($order->id);
        $this->assertNull($staleSnapshot->callback_no);

        // TX-001 wins and records its receipt.
        $first = $method->invoke($controller, Order::findOrFail($order->id), 'EPAY-RACE-1', $verify);
        $this->assertTrue($first);
        $afterFirst = Order::findOrFail($order->id);
        $this->assertSame('EPAY-RACE-1', $afterFirst->callback_no);
        $this->assertNotNull($afterFirst->paid_at);

        // The losing handler's snapshot was never refreshed: in
        // memory it still claims there is no receipt.
        $this->assertNull($staleSnapshot->callback_no);

        $logged = [];
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Log\Events\MessageLogged::class,
            function ($event) use (&$logged) {
                $logged[] = $event;
            }
        );

        // TX-002 now runs with its stale snapshot. What this test
        // proves: the accept/reject decision is taken from the
        // transaction's locked re-read of the committed row, NOT
        // from the caller's snapshot — the function sees TX-001,
        // treats TX-002 as the C1 conflict and refuses it. Under
        // the pre-R1 code the stale NULL passed the conflict check,
        // TX-002 overwrote TX-001 and this call returned true.
        //
        // What it does NOT prove: true two-connection lock
        // contention. This harness runs on per-connection in-memory
        // SQLite (a second connection would see a different, empty
        // database) and SQLite's grammar drops the FOR UPDATE
        // clause, so the row lock itself cannot be exercised here.
        // In production (MySQL/InnoDB) SELECT ... FOR UPDATE is
        // what serializes the two callbacks so the loser blocks
        // until the winner commits and then re-reads its row —
        // that lock is the final guarantee; this test pins the
        // decision logic that runs once the lock is held.
        $second = $method->invoke($controller, $staleSnapshot, 'EPAY-RACE-2', $verify);
        $this->assertFalse($second);

        // The first receipt survives untouched.
        $afterSecond = Order::findOrFail($order->id);
        $this->assertSame(Order::STATUS_CANCELLED, $afterSecond->status);
        $this->assertSame('EPAY-RACE-1', $afterSecond->callback_no);
        $this->assertSame($afterFirst->paid_at, $afterSecond->paid_at);

        // And the conflict is on record for manual reconciliation.
        $conflict = collect($logged)->first(fn ($event) => $event->level === 'error'
            && str_contains($event->message, 'conflicting callback_no'));
        $this->assertNotNull($conflict, 'expected an error log entry for the conflicting callback_no');
        $this->assertSame('EPAY-RACE-1', $conflict->context['recorded_callback_no'] ?? null);
        $this->assertSame('EPAY-RACE-2', $conflict->context['callback_no'] ?? null);
    }

    // ---------------- SB-MONEY-004 ----------------

    public function test_two_manual_admin_confirmations_both_succeed(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();
        $orderA = $this->makeOrder($user, $plan);
        $orderB = $this->makeOrder($user, $plan);
        $controller = new AdminOrderController();

        $respA = $controller->paid(Request::create('/', 'POST', ['trade_no' => $orderA->trade_no]));
        $respB = $controller->paid(Request::create('/', 'POST', ['trade_no' => $orderB->trade_no]));

        $this->assertSame(200, $respA->getStatusCode());
        $this->assertSame(200, $respB->getStatusCode(), 'second manual confirmation must not collide on callback_no');
        $freshA = Order::findOrFail($orderA->id);
        $freshB = Order::findOrFail($orderB->id);
        $this->assertSame('manual_operation_' . $orderA->trade_no, $freshA->callback_no);
        $this->assertSame('manual_operation_' . $orderB->trade_no, $freshB->callback_no);
        $this->assertSame(Order::STATUS_COMPLETED, $freshA->status);
        $this->assertSame(Order::STATUS_COMPLETED, $freshB->status);
    }

    // ---------------- SB-MONEY-006 ----------------

    public function test_surplus_ignores_fully_lapsed_historical_segment(): void
    {
        $now = 1764000000;
        Carbon::setTestNow(Carbon::createFromTimestamp($now));
        try {
            $plan = $this->makePlan();
            // Current segment: a quarterly order paid 10 days ago; the
            // account expiry is exactly that order's 3-month window.
            $segmentStart = $now - 10 * 86400;
            $expiredAt = Carbon::createFromTimestamp($segmentStart)->addMonths(3)->timestamp;
            $user = $this->makeUser([
                'plan_id' => $plan->id,
                'expired_at' => $expiredAt,
                'transfer_enable' => $plan->transfer_enable,
            ]);
            // Fully lapsed historical order: monthly, paid 200 days ago,
            // its window ended long before the current segment began.
            Order::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'type' => Order::TYPE_NEW_PURCHASE,
                'period' => Plan::PERIOD_MONTHLY,
                'trade_no' => 'MONEY1-OLD-LAPSED',
                'total_amount' => 2900,
                'balance_amount' => 0,
                'surplus_amount' => 0,
                'surplus_credit' => 0,
                'status' => Order::STATUS_COMPLETED,
                'commission_status' => 0,
                'commission_balance' => 0,
                'paid_at' => $now - 200 * 86400,
                'created_at' => $now - 200 * 86400,
                'updated_at' => $now - 200 * 86400,
            ]);
            $current = Order::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'type' => Order::TYPE_NEW_PURCHASE,
                'period' => Plan::PERIOD_QUARTERLY,
                'trade_no' => 'MONEY1-CURRENT-SEGMENT',
                'total_amount' => 7900,
                'balance_amount' => 0,
                'surplus_amount' => 0,
                'surplus_credit' => 0,
                'status' => Order::STATUS_COMPLETED,
                'commission_status' => 0,
                'commission_balance' => 0,
                'paid_at' => $segmentStart,
                'created_at' => $segmentStart,
                'updated_at' => $segmentStart,
            ]);
            $upgradeOrder = $this->makeOrder($user, $plan, ['type' => Order::TYPE_UPGRADE]);

            $service = new OrderService($upgradeOrder);
            $method = new \ReflectionMethod($service, 'getSurplusValue');
            $method->setAccessible(true);
            $method->invoke($service, $user, $upgradeOrder);

            // Only the current segment participates: the lapsed order is
            // neither in the credit base nor marked for discounting.
            $this->assertSame([$current->id], $upgradeOrder->surplus_order_ids);
            $expected = (int) (7900 * ($expiredAt - $now) / ($expiredAt - $segmentStart));
            $this->assertSame($expected, $upgradeOrder->surplus_amount);
        } finally {
            Carbon::setTestNow();
        }
    }
}
