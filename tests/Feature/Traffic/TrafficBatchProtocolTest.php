<?php

namespace Tests\Feature\Traffic;

use App\Jobs\TrafficFetchJob;
use App\Models\Plan;
use App\Models\Server;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\User;
use App\Services\TrafficBatchGuard;
use App\Services\TrafficResetService;
use App\Models\TrafficResetLog;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * SB-TA batch 2 (traffic & auth group):
 *  - TA-001: an unacknowledged batch re-sent verbatim after new traffic
 *    arrived must not re-count the old deltas; the new traffic travels
 *    in its own batch and counts exactly once.
 *  - TA-002: two independent samples with identical content but
 *    different batch ids both count; the same id with different
 *    content is refused as a conflict.
 *  - TA-003: out-of-group users and out-of-bounds deltas are rejected
 *    per item (and logged), valid items in the same batch still count.
 *  - TA-004: a batch collected before a traffic reset does not charge
 *    the new quota period even when its job runs after the reset.
 */
class TrafficBatchProtocolTest extends TestCase
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
        try {
            DB::purge();
            DB::disconnect();
        } catch (\Throwable $e) {
        }
        gc_collect_cycles();

        parent::setUp();
        config()->set('cache.stores.redis', ['driver' => 'array']);
        \Illuminate\Support\Facades\Cache::forgetDriver('redis');
        app()->forgetInstance(\App\Support\Setting::class);
        Redis::shouldReceive('sadd')->andReturn(1);
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => 'ta-test-' . uniqid() . '@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-0000000005' . random_int(10, 99),
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

    private function makeServer(array $overrides = []): Server
    {
        return Server::create(array_merge([
            'name' => 'TA Test Node',
            'type' => 'vless',
            'host' => 'ta.example.test',
            'port' => '443',
            'server_port' => 443,
            'group_ids' => [],
            'show' => 1,
            'enabled' => true,
            'rate' => 1,
            'sort' => 901,
            'protocol_settings' => [],
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function meta(string $batchId, ?int $from = null, ?int $to = null): array
    {
        return [
            'batch_id' => $batchId,
            'collected_from' => $from ?? time() - 60,
            'collected_to' => $to ?? time(),
        ];
    }

    // ---------------- TA-001 / TA-002 ----------------

    public function test_identified_batch_replay_does_not_double_count_and_new_batch_counts(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer();
        $data = [$user->id => [1000, 2000]];

        (new UserService())->trafficFetch($server, 'vless', $data, $this->meta('batch-0001'));
        $this->assertSame(1000, (int) User::findOrFail($user->id)->u);
        $this->assertSame(1000, (int) StatUser::where('user_id', $user->id)->firstOrFail()->u);
        $this->assertSame(1000, (int) StatServer::where('server_id', $server->id)->firstOrFail()->u);

        // TA-001 trigger: the panel processed batch-0001 but the agent
        // never saw the ack; meanwhile new traffic accumulated. The
        // agent re-sends batch-0001 VERBATIM — nothing may move.
        (new UserService())->trafficFetch($server, 'vless', $data, $this->meta('batch-0001'));
        $this->assertSame(1000, (int) User::findOrFail($user->id)->u);
        $this->assertSame(2000, (int) User::findOrFail($user->id)->d);
        $this->assertSame(1000, (int) StatUser::where('user_id', $user->id)->firstOrFail()->u);

        // The new traffic arrives as its own batch with identical
        // content (TA-002 trigger): it must count — content equality is
        // not duplication when the batch ids differ.
        (new UserService())->trafficFetch($server, 'vless', $data, $this->meta('batch-0002'));
        $this->assertSame(2000, (int) User::findOrFail($user->id)->u);
        $this->assertSame(4000, (int) User::findOrFail($user->id)->d);
        $this->assertSame(2000, (int) StatUser::where('user_id', $user->id)->firstOrFail()->u);
        $this->assertSame(2000, (int) StatServer::where('server_id', $server->id)->firstOrFail()->u);
    }

    public function test_same_batch_id_with_different_content_is_refused(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer();

        (new UserService())->trafficFetch($server, 'vless', [$user->id => [100, 100]], $this->meta('batch-0007'));
        $this->assertSame(100, (int) User::findOrFail($user->id)->u);

        // Same id, different content: protocol conflict — refuse, do
        // not fold into either batch. Since SB-TA-B01 the refusal
        // happens synchronously at durable receipt and is signalled
        // with TrafficBatchConflictException, which the report
        // endpoint converts to HTTP 409 (see TrafficBatchInboxTest);
        // at this service level the exception IS the refusal.
        $this->expectException(\App\Exceptions\TrafficBatchConflictException::class);
        try {
            (new UserService())->trafficFetch($server, 'vless', [$user->id => [999, 999]], $this->meta('batch-0007'));
        } finally {
            $this->assertSame(100, (int) User::findOrFail($user->id)->u);
            $this->assertSame(100, (int) StatUser::where('user_id', $user->id)->firstOrFail()->u);
        }
    }

    public function test_legacy_batches_keep_content_hash_fallback(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer();
        $data = [$user->id => [700, 700]];

        // No envelope (old agent): first delivery counts, the identical
        // replay is deduped by content hash exactly as in AUD-022.
        (new UserService())->trafficFetch($server, 'vless', $data);
        (new UserService())->trafficFetch($server, 'vless', $data);
        $this->assertSame(700, (int) User::findOrFail($user->id)->u);

        // An identified batch and a legacy batch never share a dedup
        // window: the same content under a batch id still counts.
        (new UserService())->trafficFetch($server, 'vless', $data, $this->meta('batch-legacy-mix'));
        $this->assertSame(1400, (int) User::findOrFail($user->id)->u);
    }

    // ---------------- TA-003 ----------------

    public function test_out_of_group_and_out_of_bounds_items_are_rejected_per_item(): void
    {
        $inGroup = $this->makeUser(['group_id' => 7]);
        $outGroup = $this->makeUser(['group_id' => 8]);
        $server = $this->makeServer(['group_ids' => [7]]);

        (new UserService())->trafficFetch($server, 'vless', [
            $inGroup->id => [100, 200],
            $outGroup->id => [5000, 5000],
        ]);
        $this->assertSame(100, (int) User::findOrFail($inGroup->id)->u);
        $this->assertSame(0, (int) User::findOrFail($outGroup->id)->u);

        // Negative, fractional and absurd deltas are dropped per item;
        // the valid item in the same batch still counts.
        (new UserService())->trafficFetch($server, 'vless', [
            $inGroup->id => [-50, 10],
        ], $this->meta('batch-neg'));
        $this->assertSame(100, (int) User::findOrFail($inGroup->id)->u);

        (new UserService())->trafficFetch($server, 'vless', [
            $inGroup->id => [10.5, 10],
        ], $this->meta('batch-frac'));
        $this->assertSame(100, (int) User::findOrFail($inGroup->id)->u);

        (new UserService())->trafficFetch($server, 'vless', [
            $inGroup->id => [UserService::MAX_TRAFFIC_DELTA + 1, 0],
        ], $this->meta('batch-huge'));
        $this->assertSame(100, (int) User::findOrFail($inGroup->id)->u);

        (new UserService())->trafficFetch($server, 'vless', [
            $inGroup->id => [25, 25],
        ], $this->meta('batch-ok'));
        $this->assertSame(125, (int) User::findOrFail($inGroup->id)->u);
    }

    // ---------------- TA-004 ----------------

    public function test_batch_collected_before_reset_does_not_charge_new_period(): void
    {
        $plan = Plan::create([
            'group_id' => null,
            'transfer_enable' => 200,
            'name' => 'TA Reset Plan',
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
        $user = $this->makeUser([
            'plan_id' => $plan->id,
            'u' => 500,
            'd' => 500,
            'expired_at' => time() + 30 * 86400,
        ]);
        $server = $this->makeServer();
        $serverArray = $server->toArray();
        $serverArray['rate'] = 1.0;

        // Original trigger sequence: the batch was collected (and its
        // job queued) BEFORE the reset, but the job only runs AFTER
        // the reset transaction committed.
        $data = [$user->id => [300, 300]];
        $contentHash = TrafficBatchGuard::hashFor($server->id, 'vless', $data);
        $batchKey = TrafficBatchGuard::keyForBatchId($server->id, 'batch-old-period', 0);
        $job = new TrafficFetchJob(
            $serverArray,
            $data,
            'vless',
            strtotime(date('Y-m-d')),
            $batchKey,
            'batch-old-period',
            $contentHash,
            time() - 3600,
            time() - 3500
        );

        $this->assertTrue(app(TrafficResetService::class)->performReset(
            User::findOrFail($user->id),
            TrafficResetLog::SOURCE_CRON
        ));
        $this->assertSame(0, (int) User::findOrFail($user->id)->u);

        $job->handle();

        // The old-period batch must not eat into the new period.
        $this->assertSame(0, (int) User::findOrFail($user->id)->u);
        $this->assertSame(0, (int) User::findOrFail($user->id)->d);

        // A batch collected after the reset charges normally. The
        // window is anchored strictly after the recorded reset time
        // (a same-second window would be ambiguous by construction).
        $resetAt = (int) User::findOrFail($user->id)->last_reset_at;
        $data2 = [$user->id => [40, 60]];
        $job2 = new TrafficFetchJob(
            $serverArray,
            $data2,
            'vless',
            strtotime(date('Y-m-d')),
            TrafficBatchGuard::keyForBatchId($server->id, 'batch-new-period', 0),
            'batch-new-period',
            TrafficBatchGuard::hashFor($server->id, 'vless', $data2),
            $resetAt + 1,
            $resetAt + 10
        );
        $job2->handle();
        $this->assertSame(40, (int) User::findOrFail($user->id)->u);
        $this->assertSame(60, (int) User::findOrFail($user->id)->d);
    }

    // ---------------- TA-B02 (GPT re-review closure of TA-004) ----------------

    /**
     * The GPT re-review sequence is: job reads last_reset_at (T0) ->
     * reset commits (T1) -> job increments, charging the OLD period to
     * the NEW one. The fix makes the attribution decision and the u/d
     * updates one transaction whose last_reset_at values are re-read
     * under lockForUpdate in ascending id order (the lock order
     * performReset serializes on): a concurrent reset then either
     * commits before the locked read (and is seen) or waits for this
     * transaction (and resets counters that already include the
     * batch). Both orders are correct.
     *
     * Harness limitation, stated plainly (same disclosure pattern as
     * SB-MONEY-002-C1-R1, accepted by GPT there): the test database is
     * a single in-memory SQLite connection, and SQLite discards the
     * FOR UPDATE clause, so a true two-connection InnoDB lock
     * contention CANNOT be reproduced here. What this test pins is the
     * decision mechanism the fix introduces — the attribution read is
     * an id-ordered locking re-read taken INSIDE the job's transaction
     * (after the batch claim, before the increments), never an
     * unlocked read whose result could predate a concurrent reset.
     * On the pre-fix code the attribution read is a plain unordered
     * pluck(), and this test fails (verified via git stash against
     * the batch-2 baseline). Production MySQL/InnoDB lockForUpdate is
     * the final serialization guarantee.
     */
    public function test_period_attribution_uses_locked_ordered_reread_inside_the_transaction(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer();
        $serverArray = $server->toArray();
        $serverArray['rate'] = 1.0;

        $data = [$user->id => [300, 300]];
        $job = new TrafficFetchJob(
            $serverArray,
            $data,
            'vless',
            strtotime(date('Y-m-d')),
            TrafficBatchGuard::keyForBatchId($server->id, 'batch-b02-pin', 0),
            'batch-b02-pin',
            TrafficBatchGuard::hashFor($server->id, 'vless', $data),
            time() - 3600,
            time() - 3500
        );

        $queries = [];
        DB::listen(function ($event) use (&$queries) {
            $queries[] = $event->sql;
        });
        $job->handle();

        // Locate, in execution order: the batch claim insert, the
        // attribution read of last_reset_at, and the first u/d update.
        $claimIdx = $attributionIdx = $updateIdx = null;
        foreach ($queries as $i => $sql) {
            if ($claimIdx === null && str_contains($sql, 'insert or ignore into "suzu_traffic_batches"')) {
                $claimIdx = $i;
            }
            if ($attributionIdx === null && str_contains($sql, '"v2_user"') && str_contains($sql, 'last_reset_at') && str_starts_with($sql, 'select')) {
                $attributionIdx = $i;
            }
            if ($updateIdx === null && str_starts_with($sql, 'update "v2_user"')) {
                $updateIdx = $i;
            }
        }

        $this->assertNotNull($claimIdx, 'batch claim insert must run');
        $this->assertNotNull($attributionIdx, 'attribution read of last_reset_at must run');
        $this->assertNotNull($updateIdx, 'u/d increment must run');
        // One transaction, decision before writes: claim -> locked
        // re-read -> increments.
        $this->assertLessThan($attributionIdx, $claimIdx);
        $this->assertLessThan($updateIdx, $attributionIdx);
        // The re-read locks the user rows in ascending id order (the
        // compiled FOR UPDATE is stripped by the SQLite grammar, but
        // the id ordering that accompanies lockForUpdate in the fixed
        // code is observable — and absent from the pre-fix pluck()).
        $this->assertStringContainsString(
            'order by "id" asc',
            $queries[$attributionIdx],
            'attribution read must re-read last_reset_at under lockForUpdate in ascending id order'
        );

        // Behavioural sanity: with no reset in play, the batch charges.
        $this->assertSame(300, (int) User::findOrFail($user->id)->u);
    }
}
