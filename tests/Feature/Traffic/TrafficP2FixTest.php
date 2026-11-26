<?php

namespace Tests\Feature\Traffic;

use App\Console\Commands\CheckTrafficExceeded;
use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Jobs\TrafficFetchJob;
use App\Models\Plan;
use App\Models\Server;
use App\Models\TrafficPendingCheck;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\TrafficResetLog;
use App\Models\User;
use App\Services\StatisticalService;
use App\Services\TrafficBatchGuard;
use App\Services\TrafficResetService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * P2 sweep batch 2 (traffic statistics & reset):
 *  - SB-TA-005  batch claims use conflict-tolerant inserts (PostgreSQL
 *               aborted-transaction portability defect);
 *  - SB-TA-006  over-quota check events persist inside the quota
 *               transaction instead of a post-commit Redis write;
 *  - SB-TA-006-R1 pending-check rows carry an event version; the
 *               consumer deletes only the version it read, so an
 *               event re-booked mid-check survives to the next round;
 *  - SB-TA-007  stat jobs bucket by the batch receipt timestamp, not
 *               the worker execution clock;
 *  - SB-TA-008  monthly/yearly reset date construction clamps the
 *               anchor day before comparing (no month skipping);
 *  - SB-TA-009  server rank aggregates use the same range filter as
 *               the existence check;
 *  - SB-TA-011  manual reset forwards reason + admin id into the log;
 *  - INBOX-PLUCK ResetLog prune excludes protected batch families via
 *               subquery instead of loading keys into PHP;
 *  - v2_stat hazard (stock item): AUD-036 keys verified present;
 *               v2_stat itself closed on evidence (single 'd' writer).
 */
class TrafficP2FixTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
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
        // StatisticalService's constructor asks the facade for a
        // connection object; hand it a mock so no real Redis is needed.
        Redis::shouldReceive('connection')->andReturn(\Mockery::mock());
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => 'ta-p2-' . uniqid() . '@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-0000000007' . random_int(10, 99),
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
            'name' => 'P2 Test Plan',
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
            'name' => 'P2 Node',
            'type' => 'vless',
            'host' => 'p2.example.test',
            'port' => '443',
            'server_port' => 443,
            'group_ids' => [],
            'show' => 1,
            'enabled' => true,
            'rate' => 1,
            'sort' => 903,
            'protocol_settings' => [],
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    // ---------------- SB-TA-005 ----------------

    public function test_claim_uses_conflict_tolerant_insert_and_replay_keeps_transaction_usable(): void
    {
        $hash = hash('sha256', 'ta005-claim');

        DB::enableQueryLog();
        $first = TrafficBatchGuard::claim($hash, 7, TrafficBatchGuard::PROCESSOR_FETCH);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertTrue($first);
        $insertSql = collect($queries)
            ->pluck('query')
            ->first(fn($q) => str_contains($q, 'suzu_traffic_batches') && str_contains(strtolower($q), 'insert'));
        $this->assertNotNull($insertSql, 'claim insert not found in query log');
        // SQLite grammar for insertOrIgnore; MySQL renders
        // "insert ignore", PostgreSQL "on conflict do nothing" — all
        // are the conflict-tolerant forms SB-TA-005 requires. The old
        // plain insert (followed by a catch-block probe inside a
        // possibly aborted transaction) matches none of them.
        $this->assertMatchesRegularExpression(
            '/insert or ignore|insert ignore|on conflict/i',
            $insertSql
        );

        // Replay inside one transaction: the second claim reports
        // "already processed" and the transaction stays usable for
        // the caller's own writes afterwards.
        DB::transaction(function () use ($hash) {
            $this->assertFalse(TrafficBatchGuard::claim($hash, 7, TrafficBatchGuard::PROCESSOR_FETCH));
            DB::table('suzu_traffic_batches')->insert([
                'batch_hash' => hash('sha256', 'ta005-marker'),
                'server_id' => 7,
                'processor' => TrafficBatchGuard::PROCESSOR_STAT_USER,
                'created_at' => time(),
            ]);
        });
        $this->assertEquals(
            1,
            DB::table('suzu_traffic_batches')->where('batch_hash', hash('sha256', 'ta005-marker'))->count()
        );
    }

    public function test_claim_identified_replay_and_conflict_semantics_preserved(): void
    {
        $key = TrafficBatchGuard::keyForBatchId(7, 'ta005-batch', 0);
        $contentA = hash('sha256', 'content-a');
        $contentB = hash('sha256', 'content-b');

        $this->assertSame(
            TrafficBatchGuard::CLAIM_OK,
            TrafficBatchGuard::claimIdentified($key, 7, TrafficBatchGuard::PROCESSOR_FETCH, $contentA)
        );
        $this->assertSame(
            TrafficBatchGuard::CLAIM_REPLAY,
            TrafficBatchGuard::claimIdentified($key, 7, TrafficBatchGuard::PROCESSOR_FETCH, $contentA)
        );
        $this->assertSame(
            TrafficBatchGuard::CLAIM_CONFLICT,
            TrafficBatchGuard::claimIdentified($key, 7, TrafficBatchGuard::PROCESSOR_FETCH, $contentB)
        );
    }

    public function test_receive_identified_replay_and_conflict_semantics_preserved(): void
    {
        $key = TrafficBatchGuard::keyForBatchId(7, 'ta005-inbox', 0);
        $contentA = hash('sha256', 'content-a');
        $contentB = hash('sha256', 'content-b');

        $this->assertSame(
            TrafficBatchGuard::RECEIPT_OK,
            TrafficBatchGuard::receiveIdentified($key, 7, $contentA, '{"data":{}}')
        );
        $this->assertSame(
            TrafficBatchGuard::RECEIPT_REPLAY,
            TrafficBatchGuard::receiveIdentified($key, 7, $contentA, '{"data":{}}')
        );
        $this->assertSame(
            TrafficBatchGuard::RECEIPT_CONFLICT,
            TrafficBatchGuard::receiveIdentified($key, 7, $contentB, '{"data":{"x":1}}')
        );
    }

    // ---------------- SB-TA-006 ----------------

    public function test_fetch_job_persists_pending_check_inside_transaction_even_if_redis_is_down(): void
    {
        // The old shape wrote the signal to Redis AFTER the quota
        // transaction committed; with Redis down the signal was lost
        // and a retry never re-attempted it (batch claim short-circuit).
        Redis::shouldReceive('sadd')->andThrow(new \RuntimeException('redis is down'));

        $user = $this->makeUser();
        $server = $this->makeServer();
        $job = new TrafficFetchJob(
            ['id' => $server->id, 'rate' => 1],
            [$user->id => [1000, 2000]],
            'vless',
            strtotime(date('Y-m-d')),
            null,
            null,
            null,
            null,
            null
        );
        $job->handle();

        $this->assertSame(3000, (int) $user->fresh()->u + (int) $user->fresh()->d);
        $this->assertEquals(
            1,
            DB::table('suzu_traffic_pending_checks')->where('user_id', $user->id)->count(),
            'over-quota check event must be persisted with the quota transaction'
        );
    }

    public function test_check_traffic_exceeded_consumes_durable_rows_and_survives_redis_failure(): void
    {
        $exceeded = $this->makeUser(['transfer_enable' => 1000, 'u' => 600, 'd' => 500]);
        $normal = $this->makeUser(['transfer_enable' => 100000, 'u' => 10, 'd' => 10]);
        DB::table('suzu_traffic_pending_checks')->insert([
            ['user_id' => $exceeded->id, 'created_at' => time()],
            ['user_id' => $normal->id, 'created_at' => time()],
        ]);

        // Legacy Redis drain must never block the durable path.
        Redis::shouldReceive('scard')->andThrow(new \RuntimeException('redis is down'));

        $exit = Artisan::call('check:traffic-exceeded');

        $this->assertSame(0, $exit);
        $this->assertSame(0, DB::table('suzu_traffic_pending_checks')->count());
    }

    // ---------------- SB-TA-006-R1 (event versions) ----------------

    public function test_pending_check_rebooking_advances_version(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();

        TrafficPendingCheck::recordForUsers([$user->id, $other->id]);
        $this->assertSame(
            1,
            (int) DB::table('suzu_traffic_pending_checks')->where('user_id', $user->id)->value('version'),
            'first booking must create version 1'
        );

        // A repeated booking must NOT be folded away by the unique
        // key (the old insertOrIgnore shape): it advances the event
        // version so consumers can tell the events apart.
        TrafficPendingCheck::recordForUsers([$user->id]);
        TrafficPendingCheck::recordForUsers([$user->id, $other->id]);

        $this->assertSame(2, DB::table('suzu_traffic_pending_checks')->count(), 'still one row per user');
        $this->assertSame(
            3,
            (int) DB::table('suzu_traffic_pending_checks')->where('user_id', $user->id)->value('version'),
            'each re-booking must advance the version by exactly one'
        );
        $this->assertSame(
            2,
            (int) DB::table('suzu_traffic_pending_checks')->where('user_id', $other->id)->value('version'),
            'versions advance per user, independently'
        );
    }

    public function test_pending_check_rebooked_during_consume_survives_conditional_delete(): void
    {
        // GPT's reproduction sequence for the TA-006 No-Go: consumer
        // reads the pending row, a producer books the same user
        // again before the consumer deletes, and the consumer's
        // cleanup must not erase the fresh event.
        $user = $this->makeUser();

        // 1. Producer books the first event.
        TrafficPendingCheck::recordForUsers([$user->id]);

        // 2. Consumer reads (user_id, version) — its claim on the
        //    events it is about to check.
        $chunk = TrafficPendingCheck::nextChunk();
        $this->assertCount(1, $chunk);
        $this->assertSame($user->id, $chunk[0]->user_id);
        $this->assertSame(1, $chunk[0]->version);

        // 3. While the check runs, the producer books a NEW event
        //    for the same user (new quota booking lands).
        TrafficPendingCheck::recordForUsers([$user->id]);

        // 4. Consumer finishes and deletes what it consumed. The row
        //    now carries version 2 — an event the consumer never
        //    checked — so it must survive.
        $deleted = TrafficPendingCheck::deleteConsumed($chunk);
        $this->assertSame(0, $deleted, 'stale-version delete must not remove a re-booked row');
        $survivor = DB::table('suzu_traffic_pending_checks')->where('user_id', $user->id)->first();
        $this->assertNotNull($survivor, 'the re-booked event must survive for the next round');
        $this->assertSame(2, (int) $survivor->version);

        // 5. The next consumer round sees the surviving event…
        $nextChunk = TrafficPendingCheck::nextChunk();
        $this->assertCount(1, $nextChunk);
        $this->assertSame(2, $nextChunk[0]->version);

        // …and the real command consumes it end-to-end.
        Redis::shouldReceive('scard')->andThrow(new \RuntimeException('redis is down'));
        $exit = Artisan::call('check:traffic-exceeded');
        $this->assertSame(0, $exit);
        $this->assertSame(0, DB::table('suzu_traffic_pending_checks')->count());
    }

    // ---------------- SB-TA-007 ----------------

    public function test_stat_jobs_bucket_by_receipt_timestamp_not_execution_clock(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer();
        $serverArray = ['id' => $server->id, 'rate' => 1, 'type' => 'vless'];
        $receiptDay = strtotime('2026-11-20 23:59:00');

        (new StatUserJob($serverArray, [$user->id => [100, 200]], 'vless', 'd', null, null, null, $receiptDay))->handle();
        (new StatServerJob($serverArray, [$user->id => [100, 200]], 'vless', 'd', null, null, null, $receiptDay))->handle();

        $expectedDay = strtotime('2026-11-20');
        $this->assertSame(
            $expectedDay,
            (int) StatUser::where('user_id', $user->id)->first()->record_at,
            'user stat must land in the receipt day bucket'
        );
        $this->assertSame(
            $expectedDay,
            (int) StatServer::where('server_id', $server->id)->first()->record_at,
            'server stat must land in the same receipt day bucket'
        );

        // Monthly record type buckets to the receipt month.
        (new StatUserJob($serverArray, [$user->id => [1, 1]], 'vless', 'm', null, null, null, $receiptDay))->handle();
        $monthly = StatUser::where('user_id', $user->id)->where('record_type', 'm')->first();
        $this->assertNotNull($monthly);
        $this->assertSame(strtotime('2026-11-01'), (int) $monthly->record_at);
    }

    public function test_traffic_fetch_dispatches_both_stat_jobs_with_the_receipt_timestamp(): void
    {
        Queue::fake();
        $user = $this->makeUser();
        $server = $this->makeServer();

        (new \App\Services\UserService())->trafficFetch($server, 'vless', [$user->id => [10, 20]]);

        $expected = strtotime(date('Y-m-d'));
        foreach ([StatUserJob::class, StatServerJob::class] as $jobClass) {
            Queue::assertPushed($jobClass, function ($job) use ($expected) {
                $ts = (function () {
                    return $this->timestamp;
                })->call($job);
                return $ts === $expected;
            });
        }
    }

    // ---------------- SB-TA-008 ----------------

    private function nextResetFor(int $method, string $from, int $expiredAt): int
    {
        Carbon::setTestNow(Carbon::createFromTimestamp(strtotime($from), config('app.timezone')));
        $plan = $this->makePlan(['reset_traffic_method' => $method]);
        $user = $this->makeUser(['plan_id' => $plan->id, 'expired_at' => $expiredAt]);
        $next = (new TrafficResetService())->calculateNextResetTime($user->fresh());
        $this->assertNotNull($next);
        return $next->timestamp;
    }

    private function ts(string $datetime): int
    {
        return Carbon::createFromTimestamp(strtotime($datetime), config('app.timezone'))->timestamp;
    }

    public function test_first_day_month_does_not_skip_february_from_jan_31(): void
    {
        $next = $this->nextResetFor(
            Plan::RESET_TRAFFIC_FIRST_DAY_MONTH,
            '2026-01-31 12:00:00',
            $this->ts('2027-06-15 00:00:00')
        );
        $this->assertSame($this->ts('2026-02-01 00:00:00'), $next);
    }

    public function test_monthly_anchor_31_clamps_to_april_30_within_current_month(): void
    {
        $next = $this->nextResetFor(
            Plan::RESET_TRAFFIC_MONTHLY,
            '2026-04-15 12:00:00',
            $this->ts('2026-05-31 08:30:00')
        );
        $this->assertSame($this->ts('2026-04-30 08:30:00'), $next);
    }

    public function test_monthly_anchor_29_clamps_to_feb_28_within_current_month(): void
    {
        $next = $this->nextResetFor(
            Plan::RESET_TRAFFIC_MONTHLY,
            '2025-02-10 12:00:00',
            $this->ts('2025-03-29 09:00:00')
        );
        $this->assertSame($this->ts('2025-02-28 09:00:00'), $next);
    }

    public function test_monthly_anchor_31_rolls_from_late_january_to_feb_28(): void
    {
        // Current-month candidate (Jan 31 08:00) has passed by 09:00;
        // the next candidate must be the clamped Feb 28, not Mar 31
        // and not an overflowed March date.
        $next = $this->nextResetFor(
            Plan::RESET_TRAFFIC_MONTHLY,
            '2026-01-31 09:00:00',
            $this->ts('2026-05-31 08:00:00')
        );
        $this->assertSame($this->ts('2026-02-28 08:00:00'), $next);
    }

    public function test_yearly_anchor_feb_29_clamps_to_feb_28_in_non_leap_year(): void
    {
        $next = $this->nextResetFor(
            Plan::RESET_TRAFFIC_YEARLY,
            '2025-02-10 12:00:00',
            $this->ts('2024-02-29 10:00:00')
        );
        $this->assertSame($this->ts('2025-02-28 10:00:00'), $next);
    }

    public function test_yearly_anchor_feb_29_after_this_years_date_rolls_to_next_year_feb_28(): void
    {
        $next = $this->nextResetFor(
            Plan::RESET_TRAFFIC_YEARLY,
            '2025-03-05 12:00:00',
            $this->ts('2024-02-29 10:00:00')
        );
        $this->assertSame($this->ts('2026-02-28 10:00:00'), $next);
    }

    public function test_monthly_mid_month_anchor_unchanged(): void
    {
        $next = $this->nextResetFor(
            Plan::RESET_TRAFFIC_MONTHLY,
            '2026-04-10 12:00:00',
            $this->ts('2026-05-15 08:00:00')
        );
        $this->assertSame($this->ts('2026-04-15 08:00:00'), $next);
    }

    // ---------------- SB-TA-009 ----------------

    public function test_server_rank_sums_only_in_range_daily_rows(): void
    {
        $server = $this->makeServer();
        $yesterday = Carbon::yesterday()->startOfDay()->timestamp;

        $mk = function (int $recordAt, string $type, int $u, int $d) use ($server) {
            StatServer::create([
                'record_at' => $recordAt,
                'server_id' => $server->id,
                'server_type' => 'vless',
                'record_type' => $type,
                'u' => $u,
                'd' => $d,
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        };
        $mk($yesterday, 'd', 100, 50);                 // in range
        $mk($yesterday - 86400 * 10, 'd', 10000, 9000); // out of range
        $mk($yesterday, 'm', 7777, 6666);               // in range dates, wrong type

        $rank = StatisticalService::getServerRank('yesterday');
        $row = collect($rank)->firstWhere('server_id', $server->id);

        $this->assertNotNull($row, 'server with an in-range daily row must appear in the rank');
        $this->assertSame(100, $row['u'], 'rank must aggregate only in-range daily rows');
        $this->assertSame(50, $row['d']);
    }

    // ---------------- SB-TA-011 ----------------

    public function test_manual_reset_records_reason_and_admin_id_in_log_metadata(): void
    {
        $plan = $this->makePlan();
        $user = $this->makeUser([
            'plan_id' => $plan->id,
            'expired_at' => time() + 86400 * 30,
            'u' => 12345,
            'd' => 6789,
        ]);

        $ok = (new TrafficResetService())->manualReset($user, [
            'reason' => 'abuse cleanup',
            'admin_id' => 42,
        ]);

        $this->assertTrue($ok);
        $log = TrafficResetLog::where('user_id', $user->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(TrafficResetLog::SOURCE_MANUAL, $log->trigger_source);
        $this->assertSame('abuse cleanup', $log->metadata['reason'] ?? null);
        $this->assertSame(42, $log->metadata['admin_id'] ?? null);
        $this->assertSame(0, (int) $user->fresh()->u);
    }

    public function test_automatic_reset_log_metadata_stays_null(): void
    {
        $plan = $this->makePlan();
        $user = $this->makeUser([
            'plan_id' => $plan->id,
            'expired_at' => time() + 86400 * 30,
            'u' => 100,
            'd' => 100,
        ]);

        $ok = (new TrafficResetService())->performReset($user, TrafficResetLog::SOURCE_CRON, true);
        // onlyIfDue may legitimately decline (user not due); when it
        // does run, no metadata may appear. Either way no log row may
        // carry metadata from a non-manual path.
        $log = TrafficResetLog::where('user_id', $user->id)->latest('id')->first();
        if ($log) {
            $this->assertNull($log->metadata);
        } else {
            $this->assertFalse($ok);
        }
    }

    // ---------------- INBOX-PLUCK (ResetLog prune) ----------------

    private function batchRow(string $hash, string $processor, ?string $status, int $createdAt): void
    {
        DB::table('suzu_traffic_batches')->insert([
            'batch_hash' => $hash,
            'server_id' => 7,
            'processor' => $processor,
            'content_hash' => hash('sha256', $hash . '-content'),
            'payload' => $processor === TrafficBatchGuard::PROCESSOR_INBOX ? '{"data":{}}' : null,
            'status' => $status,
            'created_at' => $createdAt,
        ]);
    }

    public function test_reset_log_prune_protects_unprocessed_families_via_subquery(): void
    {
        $aged = time() - 200000; // > 48h
        $familyA = hash('sha256', 'pluck-family-a'); // unprocessed inbox + claim
        $familyB = hash('sha256', 'pluck-family-b'); // processed inbox + claim
        $familyC = hash('sha256', 'pluck-family-c'); // legacy claim only
        $familyD = hash('sha256', 'pluck-family-d'); // fresh claim

        $this->batchRow($familyA, TrafficBatchGuard::PROCESSOR_INBOX, TrafficBatchGuard::STATUS_RECEIVED, $aged);
        $this->batchRow($familyA, TrafficBatchGuard::PROCESSOR_FETCH, null, $aged);
        $this->batchRow($familyB, TrafficBatchGuard::PROCESSOR_INBOX, TrafficBatchGuard::STATUS_PROCESSED, $aged);
        $this->batchRow($familyB, TrafficBatchGuard::PROCESSOR_FETCH, null, $aged);
        $this->batchRow($familyC, TrafficBatchGuard::PROCESSOR_FETCH, null, $aged);
        $this->batchRow($familyD, TrafficBatchGuard::PROCESSOR_FETCH, null, time());

        DB::flushQueryLog();
        DB::enableQueryLog();
        $exit = Artisan::call('reset:log');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(0, $exit);

        $count = fn(string $hash) => DB::table('suzu_traffic_batches')->where('batch_hash', $hash)->count();
        $this->assertSame(2, $count($familyA), 'unprocessed family must be fully protected');
        $this->assertSame(0, $count($familyB), 'processed family follows the 48h retention');
        $this->assertSame(0, $count($familyC), 'inbox-less legacy family follows the 48h retention');
        $this->assertSame(1, $count($familyD), 'fresh rows are never pruned');

        // The exclusion must be a subquery, not a PHP-side key list:
        // the old shape issued the delete with the protected hashes
        // bound as NOT IN (?, ?, ...) placeholders.
        $deleteSql = collect($queries)
            ->pluck('query')
            ->first(fn($q) => str_contains($q, 'suzu_traffic_batches') && str_starts_with(strtolower(ltrim($q)), 'delete'));
        $this->assertNotNull($deleteSql, 'traffic batch prune delete not found in query log');
        $this->assertMatchesRegularExpression('/not in\s*\(\s*select/i', $deleteSql);
    }

    // ---------------- v2_stat hazard (stock item, evidence closure) ----------------

    public function test_stat_unique_keys_include_record_type(): void
    {
        // SB-AUD-036 (migration 2026_11_25_000003) widened the unique
        // keys of both traffic stat tables to include record_type;
        // day and month rows share record_at on the 1st, so without
        // it a month row silently merges into the day row. This
        // guards that deployed fix against regression.
        $expect = [
            'v2_stat_user' => ['server_rate', 'user_id', 'record_at', 'record_type'],
            'v2_stat_server' => ['server_id', 'server_type', 'record_at', 'record_type'],
        ];
        foreach ($expect as $table => $columns) {
            $found = collect(Schema::getIndexes($table))
                ->contains(fn($index) => ($index['unique'] ?? false)
                    && array_values($index['columns'] ?? []) === $columns);
            $this->assertTrue($found, "{$table} must have a unique key on (" . implode(', ', $columns) . ')');
        }
    }
}
