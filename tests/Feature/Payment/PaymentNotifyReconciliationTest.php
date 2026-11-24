<?php

namespace Tests\Feature\Payment;

use App\Http\Controllers\V1\Guest\PaymentController;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Plugin;
use App\Models\User;
use App\Services\OrderService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SB-AUD payment-closure batch, end-to-end half:
 *  - AUD-002/030: a correctly-signed EPay callback whose amount does not
 *    match the order must not complete it; the correct amount completes
 *    it exactly as before.
 *  - AUD-009: upgrade surplus credit is computed from the user's real
 *    expired_at, not from a window reconstructed out of past orders.
 */
class PaymentNotifyReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // Shared sqlite file test database: release the connection so
        // no lock lingers into the next test's migration.
        \Illuminate\Support\Facades\DB::purge();
        \Illuminate\Support\Facades\DB::disconnect();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        // Same Setting/Redis workaround as the other suites: the test
        // environment has no Redis, back the hardcoded store by array.
        config()->set('cache.stores.redis', ['driver' => 'array']);
        \Illuminate\Support\Facades\Cache::forgetDriver('redis');
        app()->forgetInstance(\App\Support\Setting::class);
    }

    // ---------------- fixtures ----------------

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => 'pay-test@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-000000000301',
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
            'name' => 'Pay Test Plan',
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
            'trade_no' => 'PAYTEST' . uniqid(),
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
        // The test application boots (and marks the PluginManager
        // initialized) before fixtures exist; production starts each
        // request fresh. Reset the flag so the request middleware
        // initializes plugins with the fixture row present.
        $manager = app(\App\Services\Plugin\PluginManager::class);
        $initialized = new \ReflectionProperty($manager, 'pluginsInitialized');
        $initialized->setAccessible(true);
        $initialized->setValue($manager, false);

        return Payment::create([
            'uuid' => 'paytestuuid00000000000000000001',
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

    private function epayCallback(Order $order, string $money): array
    {
        $params = [
            'pid' => '1001',
            'type' => 'alipay',
            'out_trade_no' => $order->trade_no,
            'trade_no' => 'EPAY-TX-9',
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

    // ---------------- AUD-002/030 end-to-end ----------------

    public function test_epay_callback_with_wrong_amount_does_not_complete_order(): void
    {
        $gateway = $this->makeEpayGateway();
        $user = $this->makeUser();
        $plan = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        // Signature is valid for the reported (wrong) amount: only the
        // reconciliation against the order total can stop this.
        $response = $this->call('POST', "/api/v1/guest/payment/notify/EPay/{$gateway->uuid}", $this->epayCallback($order, '1.00'));

        $response->assertStatus(400);
        $this->assertSame(Order::STATUS_PENDING, Order::findOrFail($order->id)->status);
    }

    public function test_epay_callback_with_correct_amount_completes_order_as_before(): void
    {
        $gateway = $this->makeEpayGateway();
        $user = $this->makeUser();
        $plan = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        $response = $this->call('POST', "/api/v1/guest/payment/notify/EPay/{$gateway->uuid}", $this->epayCallback($order, '29.00'));

        $response->assertStatus(200);
        $this->assertSame('success', $response->getContent());
        $completed = Order::findOrFail($order->id);
        $this->assertSame(Order::STATUS_COMPLETED, $completed->status);
        $this->assertSame('EPAY-TX-9', $completed->callback_no);
    }

    public function test_handle_rejects_foreign_currency_report(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();
        $order = $this->makeOrder($user, $plan);

        $controller = new PaymentController();
        $method = new \ReflectionMethod($controller, 'handle');
        $method->setAccessible(true);

        $result = $method->invoke($controller, $order->trade_no, 'CB-X', [
            'paid_amount_cents' => 2900,
            'paid_currency' => 'USD',
        ]);
        $this->assertFalse($result);
        $this->assertSame(Order::STATUS_PENDING, Order::findOrFail($order->id)->status);
    }

    // ---------------- AUD-009 surplus regression ----------------

    public function test_surplus_credit_uses_real_expired_at_not_reconstructed_window(): void
    {
        $now = 1764000000;
        Carbon::setTestNow(Carbon::createFromTimestamp($now));
        try {
            $plan = $this->makePlan();
            // GPT's worked example, reconstructed: a ¥79 quarterly order
            // completed 100 days ago; the account expiry was later
            // extended outside the order flow to +227 days. The old
            // formula rebuilt expiry as (first order + 3 months), already
            // in the past, and returned ¥0 of credit.
            $user = $this->makeUser([
                'plan_id' => $plan->id,
                'expired_at' => $now + 227 * 86400,
                'transfer_enable' => $plan->transfer_enable,
            ]);
            Order::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'type' => Order::TYPE_NEW_PURCHASE,
                'period' => Plan::PERIOD_QUARTERLY,
                'trade_no' => 'PAYTEST-OLD-QUARTER',
                'total_amount' => 7900,
                'balance_amount' => 0,
                'surplus_amount' => 0,
                'surplus_credit' => 0,
                'status' => Order::STATUS_COMPLETED,
                'commission_status' => 0,
                'commission_balance' => 0,
                'created_at' => $now - 100 * 86400,
                'updated_at' => $now - 100 * 86400,
            ]);
            $upgradeOrder = $this->makeOrder($user, $plan, ['type' => Order::TYPE_UPGRADE]);

            $service = new OrderService($upgradeOrder);
            $method = new \ReflectionMethod($service, 'getSurplusValue');
            $method->setAccessible(true);
            $method->invoke($service, $user, $upgradeOrder);

            // Remaining window 227d of a 327d cycle over ¥79.00 paid:
            // 7900 * 227/327 = 5484 cents = ¥54.84 (the old code gave 0).
            $this->assertSame(5484, $upgradeOrder->surplus_amount);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_surplus_credit_is_zero_when_real_expiry_has_passed(): void
    {
        $now = 1764000000;
        Carbon::setTestNow(Carbon::createFromTimestamp($now));
        try {
            $plan = $this->makePlan();
            $user = $this->makeUser([
                'plan_id' => $plan->id,
                'expired_at' => $now - 86400,
                'transfer_enable' => $plan->transfer_enable,
            ]);
            Order::create([
                'user_id' => $user->id,
                'plan_id' => $plan->id,
                'type' => Order::TYPE_NEW_PURCHASE,
                'period' => Plan::PERIOD_QUARTERLY,
                'trade_no' => 'PAYTEST-OLD-EXPIRED',
                'total_amount' => 7900,
                'balance_amount' => 0,
                'surplus_amount' => 0,
                'surplus_credit' => 0,
                'status' => Order::STATUS_COMPLETED,
                'commission_status' => 0,
                'commission_balance' => 0,
                'created_at' => $now - 100 * 86400,
                'updated_at' => $now - 100 * 86400,
            ]);
            $upgradeOrder = $this->makeOrder($user, $plan, ['type' => Order::TYPE_UPGRADE]);

            $service = new OrderService($upgradeOrder);
            $method = new \ReflectionMethod($service, 'getSurplusValue');
            $method->setAccessible(true);
            $method->invoke($service, $user, $upgradeOrder);

            $this->assertSame(0, $upgradeOrder->surplus_amount);
        } finally {
            Carbon::setTestNow();
        }
    }
}
