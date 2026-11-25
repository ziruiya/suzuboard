<?php

namespace Tests\Feature\Payment;

use App\Console\Commands\CheckCommission;
use App\Http\Controllers\V2\Admin\OrderController as AdminOrderController;
use App\Http\Requests\Admin\OrderUpdate;
use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SB-MONEY-005: commission payout must not be repeatable and must not
 * bypass the order's payment state.
 *  - The payout command re-verifies, under a row lock inside the
 *    payout transaction, that the order is still completed and queued.
 *  - The admin panel may no longer queue unpaid/cancelled orders for
 *    payout, nor re-open an already paid-out order.
 */
class CommissionPayoutGuardTest extends TestCase
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

    private function makeUser(string $email, array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => $email,
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-' . str_pad((string) random_int(1, 999999999999), 12, '0'),
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

    private function makePlan(): Plan
    {
        return Plan::create([
            'group_id' => null,
            'transfer_enable' => 200,
            'name' => 'Commission Test Plan',
            'speed_limit' => null,
            'show' => 1,
            'sort' => 0,
            'renew' => 1,
            'prices' => [Plan::PERIOD_MONTHLY => 29],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'capacity_limit' => null,
            'sell' => 1,
            'device_limit' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }

    private function makeCommissionOrder(User $buyer, User $inviter, Plan $plan, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'user_id' => $buyer->id,
            'plan_id' => $plan->id,
            'type' => Order::TYPE_NEW_PURCHASE,
            'period' => Plan::PERIOD_MONTHLY,
            'trade_no' => 'COMM' . uniqid(),
            'total_amount' => 2900,
            'balance_amount' => 0,
            'status' => Order::STATUS_COMPLETED,
            'invite_user_id' => $inviter->id,
            'commission_status' => 1,
            'commission_balance' => 1000,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    public function test_payout_skips_queued_order_that_was_never_paid(): void
    {
        $plan = $this->makePlan();
        $inviter = $this->makeUser('inviter@example.com');
        $buyer = $this->makeUser('buyer@example.com');
        // Queued for payout by hand although the order itself is still
        // unpaid — the old command paid it out anyway.
        $order = $this->makeCommissionOrder($buyer, $inviter, $plan, [
            'status' => Order::STATUS_PENDING,
        ]);

        (new CheckCommission())->autoPayCommission();

        $this->assertSame(0, (int) $inviter->fresh()->commission_balance);
        $this->assertSame(0, CommissionLog::count());
        $this->assertSame(1, (int) Order::findOrFail($order->id)->commission_status);
    }

    public function test_payout_pays_a_completed_order_exactly_once(): void
    {
        $plan = $this->makePlan();
        $inviter = $this->makeUser('inviter@example.com');
        $buyer = $this->makeUser('buyer@example.com');
        $order = $this->makeCommissionOrder($buyer, $inviter, $plan);

        $command = new CheckCommission();
        $command->autoPayCommission();
        $command->autoPayCommission(); // a second run must not pay again

        $this->assertSame(1000, (int) $inviter->fresh()->commission_balance);
        $this->assertSame(1, CommissionLog::count());
        $this->assertSame(2, (int) Order::findOrFail($order->id)->commission_status);
    }

    public function test_admin_cannot_queue_unpaid_order_for_payout(): void
    {
        $plan = $this->makePlan();
        $inviter = $this->makeUser('inviter@example.com');
        $buyer = $this->makeUser('buyer@example.com');
        $order = $this->makeCommissionOrder($buyer, $inviter, $plan, [
            'status' => Order::STATUS_CANCELLED,
            'commission_status' => 0,
        ]);

        $request = OrderUpdate::create('/', 'POST', [
            'trade_no' => $order->trade_no,
            'commission_status' => 1,
        ]);
        $response = (new AdminOrderController())->update($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(0, (int) Order::findOrFail($order->id)->commission_status);
    }

    public function test_admin_cannot_reopen_a_paid_out_order(): void
    {
        $plan = $this->makePlan();
        $inviter = $this->makeUser('inviter@example.com');
        $buyer = $this->makeUser('buyer@example.com');
        $order = $this->makeCommissionOrder($buyer, $inviter, $plan, [
            'commission_status' => 2,
        ]);

        $request = OrderUpdate::create('/', 'POST', [
            'trade_no' => $order->trade_no,
            'commission_status' => 1,
        ]);
        $response = (new AdminOrderController())->update($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(2, (int) Order::findOrFail($order->id)->commission_status);
    }

    public function test_admin_can_queue_a_completed_order(): void
    {
        $plan = $this->makePlan();
        $inviter = $this->makeUser('inviter@example.com');
        $buyer = $this->makeUser('buyer@example.com');
        $order = $this->makeCommissionOrder($buyer, $inviter, $plan, [
            'commission_status' => 0,
        ]);

        $request = OrderUpdate::create('/', 'POST', [
            'trade_no' => $order->trade_no,
            'commission_status' => 1,
        ]);
        $response = (new AdminOrderController())->update($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, (int) Order::findOrFail($order->id)->commission_status);
    }
}
