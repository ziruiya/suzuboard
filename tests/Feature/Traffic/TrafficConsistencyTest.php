<?php

namespace Tests\Feature\Traffic;

use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Jobs\TrafficFetchJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\OrderService;
use App\Services\TrafficBatchGuard;
use App\Services\TrafficResetService;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * SB-AUD traffic consistency batch:
 *  - AUD-022: a replayed traffic batch (agent retry / queue redelivery)
 *    is counted exactly once; a different batch still counts.
 *  - AUD-036: the stat tables' unique keys include record_type, and day
 *    and month rows for the same record_at stay separate.
 *  - AUD-023: a reset works from the locked, freshly read row — stale
 *    in-memory counters are never the basis of a reset, and a due user
 *    cannot be reset twice by two callers holding stale instances.
 *  - AUD-008: when the traffic reset fails, order opening aborts
 *    instead of completing with the reset silently skipped.
 */
class TrafficConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        \Mockery::close();
        DB::purge();
        DB::disconnect();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        // The shared sqlite test file can still be locked by the
        // previous suite's unpurged connection (ServerHandshakeTest
        // errors out mid-request against the missing Redis and has no
        // purge tearDown); release any inherited connection before
        // RefreshDatabase migrates.
        try {
            DB::purge();
            DB::disconnect();
        } catch (\Throwable $e) {
        }
        gc_collect_cycles();

        parent::setUp();
        // Same Setting/Redis workaround as the other suites.
        config()->set('cache.stores.redis', ['driver' => 'array']);
        \Illuminate\Support\Facades\Cache::forgetDriver('redis');
        app()->forgetInstance(\App\Support\Setting::class);
        // TrafficFetchJob collects processed user ids into a Redis set;
        // there is no Redis in the test environment.
        Redis::shouldReceive('sadd')->andReturn(1);
    }

    // ---------------- fixtures ----------------

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => 'traffic-test-' . uniqid() . '@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-0000000004' . random_int(10, 99),
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
            'name' => 'Traffic Test Plan',
            'speed_limit' => null,
            'show' => 1,
            'sort' => 0,
            'renew' => 1,
            'prices' => [Plan::PERIOD_MONTHLY => 29, Plan::PERIOD_RESET_TRAFFIC => 10],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'capacity_limit' => null,
            'sell' => 1,
            'device_limit' => null,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function makeServer(array $overrides = []): Server
    {
        return Server::create(array_merge([
            'name' => 'Traffic Test Node',
            'type' => 'vless',
            'host' => 'traffic.example.test',
            'port' => '443',
            'server_port' => 443,
            'group_ids' => [],
            'show' => 1,
            'enabled' => true,
            'rate' => 1,
            'sort' => 900,
            'protocol_settings' => [],
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
            'trade_no' => 'TRATEST' . uniqid(),
            'total_amount' => 2900,
            'balance_amount' => 0,
            'status' => Order::STATUS_PROCESSING,
            'commission_status' => 0,
            'commission_balance' => 0,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    // ---------------- AUD-022 ----------------

    public function test_replayed_batch_is_counted_exactly_once(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer();
        $data = [$user->id => [1000, 2000]];

        (new UserService())->trafficFetch($server, 'vless', $data);

        $this->assertSame(1000, (int) User::findOrFail($user->id)->u);
        $this->assertSame(2000, (int) User::findOrFail($user->id)->d);
        $statUser = StatUser::where('user_id', $user->id)->firstOrFail();
        $this->assertSame(1000, (int) $statUser->u);
        $this->assertSame(2000, (int) $statUser->d);
        $statServer = StatServer::where('server_id', $server->id)->firstOrFail();
        $this->assertSame(1000, (int) $statServer->u);
        $this->assertSame(1000, (int) Server::findOrFail($server->id)->u);

        // Byte-identical replay (agent retry / redelivery): nothing moves.
        (new UserService())->trafficFetch($server, 'vless', $data);

        $this->assertSame(1000, (int) User::findOrFail($user->id)->u);
        $this->assertSame(2000, (int) User::findOrFail($user->id)->d);
        $this->assertSame(1000, (int) StatUser::where('user_id', $user->id)->firstOrFail()->u);
        $this->assertSame(1000, (int) StatServer::where('server_id', $server->id)->firstOrFail()->u);
        $this->assertSame(1000, (int) Server::findOrFail($server->id)->u);

        // A genuinely different batch still counts.
        (new UserService())->trafficFetch($server, 'vless', [$user->id => [500, 500]]);

        $this->assertSame(1500, (int) User::findOrFail($user->id)->u);
        $this->assertSame(2500, (int) User::findOrFail($user->id)->d);
        $this->assertSame(1500, (int) StatUser::where('user_id', $user->id)->firstOrFail()->u);
    }

    public function test_redelivered_jobs_skip_via_batch_claim(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer();
        $serverArray = $server->toArray();
        $serverArray['rate'] = 1.0;
        $data = [$user->id => [300, 700]];
        $hash = TrafficBatchGuard::hashFor($server->id, 'vless', $data);

        (new TrafficFetchJob($serverArray, $data, 'vless', strtotime(date('Y-m-d')), $hash))->handle();
        (new TrafficFetchJob($serverArray, $data, 'vless', strtotime(date('Y-m-d')), $hash))->handle();
        $this->assertSame(300, (int) User::findOrFail($user->id)->u);

        (new StatUserJob($serverArray, $data, 'vless', 'd', $hash))->handle();
        (new StatUserJob($serverArray, $data, 'vless', 'd', $hash))->handle();
        $this->assertSame(300, (int) StatUser::where('user_id', $user->id)->firstOrFail()->u);

        (new StatServerJob($serverArray, $data, 'vless', 'd', $hash))->handle();
        (new StatServerJob($serverArray, $data, 'vless', 'd', $hash))->handle();
        $this->assertSame(300, (int) StatServer::where('server_id', $server->id)->firstOrFail()->u);
        $this->assertSame(300, (int) Server::findOrFail($server->id)->u);
    }

    public function test_batch_claim_rolls_back_with_failed_processing(): void
    {
        $hash = TrafficBatchGuard::hashFor(1, 'vless', [1 => [1, 1]]);

        try {
            DB::transaction(function () use ($hash) {
                $this->assertTrue(TrafficBatchGuard::claim($hash, 1, TrafficBatchGuard::PROCESSOR_FETCH));
                throw new \RuntimeException('simulated mid-batch failure');
            });
            $this->fail('expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated mid-batch failure', $e->getMessage());
        }

        // The claim rolled back with the failed writes, so a retry owns
        // the batch; after that commit, replays are rejected.
        $this->assertTrue(TrafficBatchGuard::claim($hash, 1, TrafficBatchGuard::PROCESSOR_FETCH));
        $this->assertFalse(TrafficBatchGuard::claim($hash, 1, TrafficBatchGuard::PROCESSOR_FETCH));
        // A different processor has its own claim slot.
        $this->assertTrue(TrafficBatchGuard::claim($hash, 1, TrafficBatchGuard::PROCESSOR_STAT_USER));
    }

    // ---------------- AUD-036 ----------------

    public function test_stat_unique_keys_include_record_type(): void
    {
        $userKeys = collect(Schema::getIndexes('v2_stat_user'))
            ->filter(fn($i) => $i['unique'] ?? false)
            ->map(fn($i) => $i['columns']);
        $this->assertTrue(
            $userKeys->contains(fn($cols) => $cols === ['server_rate', 'user_id', 'record_at', 'record_type']),
            'v2_stat_user unique key must include record_type'
        );

        $serverKeys = collect(Schema::getIndexes('v2_stat_server'))
            ->filter(fn($i) => $i['unique'] ?? false)
            ->map(fn($i) => $i['columns']);
        $this->assertTrue(
            $serverKeys->contains(fn($cols) => $cols === ['server_id', 'server_type', 'record_at', 'record_type']),
            'v2_stat_server unique key must include record_type'
        );
    }

    public function test_day_and_month_stat_rows_stay_separate(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer();
        $serverArray = $server->toArray();
        $serverArray['rate'] = 1.0;
        $data = [$user->id => [100, 200]];

        (new StatUserJob($serverArray, $data, 'vless', 'd', TrafficBatchGuard::hashFor($server->id, 'vless|d', $data)))->handle();
        (new StatUserJob($serverArray, $data, 'vless', 'm', TrafficBatchGuard::hashFor($server->id, 'vless|m', $data)))->handle();

        $rows = StatUser::where('user_id', $user->id)->orderBy('record_type')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(100, (int) $rows->firstWhere('record_type', 'd')->u);
        $this->assertSame(100, (int) $rows->firstWhere('record_type', 'm')->u);

        (new StatServerJob($serverArray, $data, 'vless', 'd', TrafficBatchGuard::hashFor($server->id, 'vless|sd', $data)))->handle();
        (new StatServerJob($serverArray, $data, 'vless', 'm', TrafficBatchGuard::hashFor($server->id, 'vless|sm', $data)))->handle();

        $serverRows = StatServer::where('server_id', $server->id)->get();
        $this->assertCount(2, $serverRows);
        $this->assertSame(100, (int) $serverRows->firstWhere('record_type', 'd')->u);
        $this->assertSame(100, (int) $serverRows->firstWhere('record_type', 'm')->u);
    }

    // ---------------- AUD-023 ----------------

    public function test_perform_reset_reads_fresh_counters_under_lock(): void
    {
        $plan = $this->makePlan();
        $user = $this->makeUser([
            'plan_id' => $plan->id,
            'u' => 100,
            'd' => 50,
            'expired_at' => time() + 30 * 86400,
            'next_reset_at' => time() - 60,
            'reset_count' => 0,
        ]);

        $stale = User::findOrFail($user->id);
        // A traffic increment lands after the caller loaded its copy.
        DB::table('v2_user')->where('id', $user->id)->update(['u' => 170, 'd' => 80]);

        $result = app(TrafficResetService::class)->performReset($stale, TrafficResetLog::SOURCE_MANUAL);

        $this->assertTrue($result);
        $log = TrafficResetLog::where('user_id', $user->id)->latest('id')->firstOrFail();
        // The log must record what was actually wiped, not the stale 100/50.
        $this->assertSame(170, (int) $log->old_upload);
        $this->assertSame(80, (int) $log->old_download);

        $fresh = User::findOrFail($user->id);
        $this->assertSame(0, (int) $fresh->u);
        $this->assertSame(0, (int) $fresh->d);
        $this->assertSame(1, (int) $fresh->reset_count);
        // The caller's instance was mirrored, not left holding stale counters.
        $this->assertSame(0, (int) $stale->u);
        $this->assertSame(1, (int) $stale->reset_count);
    }

    public function test_check_and_reset_cannot_reset_twice_from_stale_instances(): void
    {
        $plan = $this->makePlan();
        $user = $this->makeUser([
            'plan_id' => $plan->id,
            'u' => 900,
            'd' => 900,
            'expired_at' => time() + 30 * 86400,
            'reset_count' => 0,
        ]);
        // UserObserver recomputes next_reset_at on creation; put the
        // user into the due state directly at the storage layer.
        DB::table('v2_user')->where('id', $user->id)->update(['next_reset_at' => time() - 60]);

        $first = User::findOrFail($user->id);
        $second = User::findOrFail($user->id);

        $service = app(TrafficResetService::class);
        $this->assertTrue($service->checkAndReset($first, TrafficResetLog::SOURCE_CRON));
        // The second caller still holds a pre-reset instance that looks
        // due; the under-lock re-check must stop the double reset.
        $this->assertFalse($service->checkAndReset($second, TrafficResetLog::SOURCE_USER_ACCESS));

        $fresh = User::findOrFail($user->id);
        $this->assertSame(1, (int) $fresh->reset_count);
        $this->assertSame(1, TrafficResetLog::where('user_id', $user->id)->count());
    }

    // ---------------- AUD-008 ----------------

    private function mockFailingResetService(): void
    {
        $mock = \Mockery::mock(TrafficResetService::class);
        $mock->shouldReceive('performReset')->andReturn(false);
        // UserObserver also resolves this service (for next_reset_at
        // recomputation); keep that path inert.
        $mock->shouldReceive('calculateNextResetTime')->andReturnNull();
        $this->app->instance(TrafficResetService::class, $mock);
    }

    public function test_reset_traffic_order_aborts_when_reset_fails(): void
    {
        $plan = $this->makePlan();
        $user = $this->makeUser(['plan_id' => $plan->id, 'u' => 5000, 'd' => 6000, 'expired_at' => time() + 30 * 86400]);
        $order = $this->makeOrder($user, $plan, ['period' => Plan::PERIOD_RESET_TRAFFIC]);
        $this->mockFailingResetService();

        try {
            (new OrderService($order))->open();
            $this->fail('open() must abort when the traffic reset fails');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('流量重置失败', $e->getMessage());
        }

        $this->assertSame(Order::STATUS_PROCESSING, Order::findOrFail($order->id)->status);
    }

    public function test_new_purchase_aborts_when_reset_fails(): void
    {
        $plan = $this->makePlan();
        $user = $this->makeUser(['u' => 5000, 'd' => 6000, 'expired_at' => null]);
        $order = $this->makeOrder($user, $plan, ['period' => Plan::PERIOD_MONTHLY, 'type' => Order::TYPE_NEW_PURCHASE]);
        $this->mockFailingResetService();

        try {
            (new OrderService($order))->open();
            $this->fail('open() must abort when the traffic reset fails');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('流量重置失败', $e->getMessage());
        }

        $freshOrder = Order::findOrFail($order->id);
        $this->assertSame(Order::STATUS_PROCESSING, $freshOrder->status);
        // The purchase was rolled back: no plan was assigned.
        $this->assertNull(User::findOrFail($user->id)->plan_id);
    }

    public function test_reset_traffic_order_completes_with_working_reset(): void
    {
        $plan = $this->makePlan();
        $user = $this->makeUser(['plan_id' => $plan->id, 'u' => 5000, 'd' => 6000, 'expired_at' => time() + 30 * 86400]);
        $order = $this->makeOrder($user, $plan, ['period' => Plan::PERIOD_RESET_TRAFFIC]);

        (new OrderService($order))->open();

        $this->assertSame(Order::STATUS_COMPLETED, Order::findOrFail($order->id)->status);
        $fresh = User::findOrFail($user->id);
        $this->assertSame(0, (int) $fresh->u);
        $this->assertSame(0, (int) $fresh->d);
    }
}
