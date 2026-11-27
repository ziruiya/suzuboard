<?php

namespace Tests\Feature\Traffic;

use App\Http\Controllers\V2\Server\ServerController;
use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Jobs\TrafficFetchJob;
use App\Models\Server;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\User;
use App\Services\TrafficBatchGuard;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * SB-TA-B01 (GPT re-review closure of TA-001/002): durable receipt.
 *
 * Protocol: for identified batches, HTTP 200 means "durably received"
 * (payload + content fingerprint + status committed to the inbox in
 * suzu_traffic_batches), NOT "booked". Booking is asynchronous and
 * recoverable: the suzu:traffic-batch-reconcile command re-drives any
 * batch whose accounting jobs never completed.
 *
 *  - ① a batch whose jobs are permanently lost is booked exactly once
 *       by the reconcile command, from the stored payload;
 *  - ② a same-id different-content re-send is refused at the HTTP
 *       layer (409), not acknowledged with 200;
 *  - ③ a same-id same-content re-send is an idempotent replay (200,
 *       counted once, no second inbox row);
 *  - ④ when the durable receipt itself cannot be committed, the
 *       report endpoint answers an HTTP error (500), never 200, so
 *       the agent keeps the batch.
 */
class TrafficBatchInboxTest extends TestCase
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
            'email' => 'ta-inbox-' . uniqid() . '@example.com',
            'password' => 'password',
            'uuid' => '00000000-0000-0000-0000-0000000006' . random_int(10, 99),
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
            'name' => 'TA Inbox Node',
            'type' => 'vless',
            'host' => 'ta-inbox.example.test',
            'port' => '443',
            'server_port' => 443,
            'group_ids' => [],
            'show' => 1,
            'enabled' => true,
            'rate' => 1,
            'sort' => 902,
            'protocol_settings' => [],
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function meta(string $batchId): array
    {
        return [
            'batch_id' => $batchId,
            'collected_from' => time() - 60,
            'collected_to' => time(),
        ];
    }

    private function inboxRow(string $batchKey)
    {
        return DB::table('suzu_traffic_batches')
            ->where('batch_hash', $batchKey)
            ->where('processor', TrafficBatchGuard::PROCESSOR_INBOX)
            ->first();
    }

    private function reportRequest(Server $server, array $traffic, array $envelope): Request
    {
        $request = Request::create('/api/v2/server/report', 'POST', [
            'traffic' => $traffic,
            'traffic_batch' => $envelope,
        ]);
        $request->attributes->set('node_info', $server);
        return $request;
    }

    // ---------------- ③ receipt + replay ----------------

    public function test_report_persists_durable_receipt_and_replay_is_idempotent(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer();
        $data = [$user->id => [1000, 2000]];
        $batchKey = TrafficBatchGuard::keyForBatchId($server->id, 'inbox-batch-1', 0);

        (new UserService())->trafficFetch($server, 'vless', $data, $this->meta('inbox-batch-1'));

        // The durable receipt exists and — the sync queue having run
        // all three processors — is already fully processed.
        $row = $this->inboxRow($batchKey);
        $this->assertNotNull($row, 'inbox receipt row must exist');
        $this->assertSame(TrafficBatchGuard::STATUS_PROCESSED, $row->status);
        $this->assertNotNull($row->processed_at);
        $payload = json_decode($row->payload, true);
        $this->assertSame([1000, 2000], $payload['data'][$user->id]);
        $this->assertSame(1000, (int) User::findOrFail($user->id)->u);

        // Same id, same content: idempotent replay — no second inbox
        // row, no re-dispatch, no double count.
        (new UserService())->trafficFetch($server, 'vless', $data, $this->meta('inbox-batch-1'));
        $this->assertSame(1, DB::table('suzu_traffic_batches')
            ->where('batch_hash', $batchKey)
            ->where('processor', TrafficBatchGuard::PROCESSOR_INBOX)
            ->count());
        $this->assertSame(1000, (int) User::findOrFail($user->id)->u);
        $this->assertSame(1000, (int) StatUser::where('user_id', $user->id)->firstOrFail()->u);
    }

    // ---------------- ② conflict refused at the HTTP layer ----------------

    public function test_conflicting_resend_is_refused_with_http_409_not_200(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer();
        $controller = new ServerController();

        $ok = $controller->report($this->reportRequest($server, [$user->id => [100, 100]], $this->meta('inbox-batch-2')));
        $this->assertSame(200, $ok->getStatusCode());
        $this->assertSame(100, (int) User::findOrFail($user->id)->u);

        // Same batch id, different content: the endpoint must refuse
        // synchronously — the agent keeps its batch instead of being
        // told "success" while the conflict is only found later in a
        // queue job.
        $refused = $controller->report($this->reportRequest($server, [$user->id => [999, 999]], $this->meta('inbox-batch-2')));
        $this->assertSame(409, $refused->getStatusCode());
        $this->assertSame(100, (int) User::findOrFail($user->id)->u);
        $this->assertSame(100, (int) StatUser::where('user_id', $user->id)->firstOrFail()->u);
    }

    // ---------------- ④ receipt failure is an HTTP error ----------------

    public function test_receipt_persistence_failure_answers_http_500_not_200(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer();
        $controller = new ServerController();

        // Simulate the inbox being unwritable: the receipt insert
        // fails, so the endpoint must NOT acknowledge the batch.
        DB::statement('ALTER TABLE suzu_traffic_batches RENAME TO suzu_traffic_batches_down');
        try {
            $response = $controller->report($this->reportRequest($server, [$user->id => [100, 100]], $this->meta('inbox-batch-3')));
        } finally {
            DB::statement('ALTER TABLE suzu_traffic_batches_down RENAME TO suzu_traffic_batches');
        }

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(0, (int) User::findOrFail($user->id)->u);
    }

    // ---------------- ① lost jobs are recovered by reconcile ----------------

    public function test_reconcile_books_a_batch_whose_jobs_were_lost_exactly_once(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer();
        $data = [$user->id => [400, 600]];
        $contentHash = TrafficBatchGuard::hashFor($server->id, 'vless', $data);
        $batchKey = TrafficBatchGuard::keyForBatchId($server->id, 'inbox-batch-lost', 0);

        // Durable receipt committed (exactly what the report request
        // does synchronously) — then every accounting job is "lost":
        // none of them ever runs.
        $payload = json_encode([
            'protocol' => 'vless',
            'timestamp' => strtotime(date('Y-m-d')),
            'server' => ['id' => $server->id, 'rate' => 1, 'type' => 'vless'],
            'data' => $data,
            'collected_from' => time() - 60,
            'collected_to' => time(),
        ]);
        $this->assertSame(
            TrafficBatchGuard::RECEIPT_OK,
            TrafficBatchGuard::receiveIdentified($batchKey, $server->id, $contentHash, $payload)
        );
        $this->assertSame(0, (int) User::findOrFail($user->id)->u);

        // The reconcile command re-drives the batch from its payload.
        $this->artisan('suzu:traffic-batch-reconcile', ['--older-than' => 0])->assertExitCode(0);
        $this->assertSame(400, (int) User::findOrFail($user->id)->u);
        $this->assertSame(600, (int) User::findOrFail($user->id)->d);
        $this->assertSame(400, (int) StatUser::where('user_id', $user->id)->firstOrFail()->u);
        $this->assertSame(400, (int) StatServer::where('server_id', $server->id)->firstOrFail()->u);
        $this->assertSame(TrafficBatchGuard::STATUS_PROCESSED, $this->inboxRow($batchKey)->status);

        // Running reconcile again (and a late agent re-send) changes
        // nothing: the batch is booked exactly once.
        $this->artisan('suzu:traffic-batch-reconcile', ['--older-than' => 0])->assertExitCode(0);
        (new UserService())->trafficFetch($server, 'vless', $data, $this->meta('inbox-batch-lost'));
        $this->assertSame(400, (int) User::findOrFail($user->id)->u);
    }

    public function test_reconcile_completes_a_partially_processed_batch_without_double_counting(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer();
        $data = [$user->id => [250, 750]];
        $contentHash = TrafficBatchGuard::hashFor($server->id, 'vless', $data);
        $batchKey = TrafficBatchGuard::keyForBatchId($server->id, 'inbox-batch-partial', 0);
        $collectedFrom = time() - 60;
        $collectedTo = time();

        $payload = json_encode([
            'protocol' => 'vless',
            'timestamp' => strtotime(date('Y-m-d')),
            'server' => ['id' => $server->id, 'rate' => 1, 'type' => 'vless'],
            'data' => $data,
            'collected_from' => $collectedFrom,
            'collected_to' => $collectedTo,
        ]);
        TrafficBatchGuard::receiveIdentified($batchKey, $server->id, $contentHash, $payload);

        // Only the quota processor ran before the "failure"; the two
        // stat processors were lost.
        $serverArray = $server->toArray();
        $serverArray['rate'] = 1.0;
        (new TrafficFetchJob($serverArray, $data, 'vless', strtotime(date('Y-m-d')), $batchKey, 'inbox-batch-partial', $contentHash, $collectedFrom, $collectedTo))->handle();
        $this->assertSame(250, (int) User::findOrFail($user->id)->u);
        $this->assertSame(TrafficBatchGuard::STATUS_RECEIVED, $this->inboxRow($batchKey)->status);

        // Reconcile drives only what is missing; the fetch claim makes
        // the re-driven fetch job a no-op.
        $this->artisan('suzu:traffic-batch-reconcile', ['--older-than' => 0])->assertExitCode(0);
        $this->assertSame(250, (int) User::findOrFail($user->id)->u);
        $this->assertSame(250, (int) StatUser::where('user_id', $user->id)->firstOrFail()->u);
        $this->assertSame(250, (int) StatServer::where('server_id', $server->id)->firstOrFail()->u);
        $this->assertSame(TrafficBatchGuard::STATUS_PROCESSED, $this->inboxRow($batchKey)->status);
    }

    // ---------------- ⑤ prune must spare unprocessed inbox rows (TA-B01-R1) ----------------

    public function test_reset_log_prune_spares_unprocessed_inbox_and_reconcile_still_books_it(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer();
        $data = [$user->id => [700, 300]];
        $contentHash = TrafficBatchGuard::hashFor($server->id, 'vless', $data);
        $batchKey = TrafficBatchGuard::keyForBatchId($server->id, 'inbox-batch-stranded', 0);
        $agedAt = time() - 200000; // safely beyond the 48-hour prune horizon

        // A durably received batch whose accounting jobs never ran
        // (queue down), then aged past 48 hours.
        $payload = json_encode([
            'protocol' => 'vless',
            'timestamp' => strtotime(date('Y-m-d')),
            'server' => ['id' => $server->id, 'rate' => 1, 'type' => 'vless'],
            'data' => $data,
            'collected_from' => time() - 60,
            'collected_to' => time(),
        ]);
        $this->assertSame(
            TrafficBatchGuard::RECEIPT_OK,
            TrafficBatchGuard::receiveIdentified($batchKey, $server->id, $contentHash, $payload)
        );
        DB::table('suzu_traffic_batches')
            ->where('batch_hash', $batchKey)
            ->where('processor', TrafficBatchGuard::PROCESSOR_INBOX)
            ->update(['created_at' => $agedAt]);

        // Same-age neighbours that MUST still be pruned: a processed
        // inbox row, and a plain claim row (status NULL, as claim rows
        // have always been written — a NULL status must not make a
        // non-inbox row look like an unprocessed inbox row).
        $processedKey = TrafficBatchGuard::keyForBatchId($server->id, 'inbox-batch-done', 0);
        DB::table('suzu_traffic_batches')->insert([
            'batch_hash' => $processedKey,
            'server_id' => $server->id,
            'processor' => TrafficBatchGuard::PROCESSOR_INBOX,
            'content_hash' => $contentHash,
            'payload' => $payload,
            'status' => TrafficBatchGuard::STATUS_PROCESSED,
            'processed_at' => $agedAt + 60,
            'created_at' => $agedAt,
        ]);
        $claimKey = TrafficBatchGuard::keyForBatchId($server->id, 'inbox-batch-claimed', 0);
        DB::table('suzu_traffic_batches')->insert([
            'batch_hash' => $claimKey,
            'server_id' => $server->id,
            'processor' => TrafficBatchGuard::PROCESSOR_FETCH,
            'created_at' => $agedAt,
        ]);

        $this->artisan('reset:log')->assertExitCode(0);

        // The unprocessed inbox row — the only surviving copy of the
        // batch — must survive the prune; the other two are gone.
        $this->assertNotNull($this->inboxRow($batchKey), 'unprocessed inbox row must not be pruned by age');
        $this->assertNull($this->inboxRow($processedKey), 'processed inbox row follows the 48h retention');
        $this->assertSame(0, DB::table('suzu_traffic_batches')->where('batch_hash', $claimKey)->count());
        $this->assertSame(0, (int) User::findOrFail($user->id)->u);

        // And because it survived, reconcile can still book it —
        // exactly once — even after the >48h outage.
        $this->artisan('suzu:traffic-batch-reconcile', ['--older-than' => 0])->assertExitCode(0);
        $this->assertSame(700, (int) User::findOrFail($user->id)->u);
        $this->assertSame(300, (int) User::findOrFail($user->id)->d);
        $this->assertSame(TrafficBatchGuard::STATUS_PROCESSED, $this->inboxRow($batchKey)->status);

        $this->artisan('suzu:traffic-batch-reconcile', ['--older-than' => 0])->assertExitCode(0);
        $this->assertSame(700, (int) User::findOrFail($user->id)->u);
    }

    // ---------------- ⑥ prune protects the whole family of an unprocessed inbox (TA-B01-R1-C1) ----------------

    public function test_reset_log_prune_spares_claims_of_unprocessed_inbox_and_reconcile_completes_exactly_once(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer();
        $data = [$user->id => [700, 300]];
        $contentHash = TrafficBatchGuard::hashFor($server->id, 'vless', $data);
        $batchId = 'inbox-batch-family-partial';
        $batchKey = TrafficBatchGuard::keyForBatchId($server->id, $batchId, 0);
        $collectedFrom = time() - 60;
        $collectedTo = time();
        $timestamp = strtotime(date('Y-m-d'));

        $payload = json_encode([
            'protocol' => 'vless',
            'timestamp' => $timestamp,
            'server' => ['id' => $server->id, 'rate' => 1, 'type' => 'vless'],
            'data' => $data,
            'collected_from' => $collectedFrom,
            'collected_to' => $collectedTo,
        ]);
        $this->assertSame(
            TrafficBatchGuard::RECEIPT_OK,
            TrafficBatchGuard::receiveIdentified($batchKey, $server->id, $contentHash, $payload)
        );

        // Two of the three processors book the batch and leave their
        // claim rows behind; the third (stat_server) is lost in the
        // outage, so the inbox stays 'received'.
        $serverArray = $server->toArray();
        $serverArray['rate'] = 1.0;
        (new TrafficFetchJob($serverArray, $data, 'vless', $timestamp, $batchKey, $batchId, $contentHash, $collectedFrom, $collectedTo))->handle();
        (new StatUserJob($serverArray, $data, 'vless', 'd', $batchKey, $batchId, $contentHash))->handle();

        $this->assertSame(700, (int) User::findOrFail($user->id)->u);
        $this->assertSame(700, (int) StatUser::where('user_id', $user->id)->firstOrFail()->u);
        $this->assertSame(0, StatServer::where('server_id', $server->id)->count());
        $this->assertSame(TrafficBatchGuard::STATUS_RECEIVED, $this->inboxRow($batchKey)->status);
        $this->assertSame(3, DB::table('suzu_traffic_batches')->where('batch_hash', $batchKey)->count());

        // The whole family ages past the 48-hour prune horizon.
        DB::table('suzu_traffic_batches')
            ->where('batch_hash', $batchKey)
            ->update(['created_at' => time() - 200000]);

        $this->artisan('reset:log')->assertExitCode(0);

        // Inbox AND both surviving claims must still be there: the
        // claims are the only thing stopping reconcile from booking
        // those two processors a second time.
        $processors = DB::table('suzu_traffic_batches')
            ->where('batch_hash', $batchKey)
            ->pluck('processor')->sort()->values()->all();
        $this->assertSame(
            [TrafficBatchGuard::PROCESSOR_INBOX, TrafficBatchGuard::PROCESSOR_STAT_USER, TrafficBatchGuard::PROCESSOR_FETCH],
            $processors,
            'claims of an unprocessed inbox family must not be pruned by age'
        );

        // Reconcile completes only the missing processor; every leg
        // of the batch ends up booked exactly once.
        $this->artisan('suzu:traffic-batch-reconcile', ['--older-than' => 0])->assertExitCode(0);
        $this->assertSame(700, (int) User::findOrFail($user->id)->u);
        $this->assertSame(300, (int) User::findOrFail($user->id)->d);
        $this->assertSame(700, (int) StatUser::where('user_id', $user->id)->firstOrFail()->u);
        $this->assertSame(700, (int) StatServer::where('server_id', $server->id)->firstOrFail()->u);
        $this->assertSame(TrafficBatchGuard::STATUS_PROCESSED, $this->inboxRow($batchKey)->status);
    }

    public function test_reset_log_prune_spares_family_when_all_claims_exist_but_inbox_flip_was_lost(): void
    {
        $user = $this->makeUser();
        $server = $this->makeServer();
        $data = [$user->id => [700, 300]];
        $contentHash = TrafficBatchGuard::hashFor($server->id, 'vless', $data);
        $batchId = 'inbox-batch-family-unflipped';
        $batchKey = TrafficBatchGuard::keyForBatchId($server->id, $batchId, 0);
        $collectedFrom = time() - 60;
        $collectedTo = time();
        $timestamp = strtotime(date('Y-m-d'));

        $payload = json_encode([
            'protocol' => 'vless',
            'timestamp' => $timestamp,
            'server' => ['id' => $server->id, 'rate' => 1, 'type' => 'vless'],
            'data' => $data,
            'collected_from' => $collectedFrom,
            'collected_to' => $collectedTo,
        ]);
        $this->assertSame(
            TrafficBatchGuard::RECEIPT_OK,
            TrafficBatchGuard::receiveIdentified($batchKey, $server->id, $contentHash, $payload)
        );

        // All three processors book the batch and hold their claims.
        $serverArray = $server->toArray();
        $serverArray['rate'] = 1.0;
        (new TrafficFetchJob($serverArray, $data, 'vless', $timestamp, $batchKey, $batchId, $contentHash, $collectedFrom, $collectedTo))->handle();
        (new StatUserJob($serverArray, $data, 'vless', 'd', $batchKey, $batchId, $contentHash))->handle();
        (new StatServerJob($serverArray, $data, 'vless', 'd', $batchKey, $batchId, $contentHash))->handle();

        $this->assertSame(700, (int) User::findOrFail($user->id)->u);
        $this->assertSame(700, (int) StatUser::where('user_id', $user->id)->firstOrFail()->u);
        $this->assertSame(700, (int) StatServer::where('server_id', $server->id)->firstOrFail()->u);
        $this->assertSame(4, DB::table('suzu_traffic_batches')->where('batch_hash', $batchKey)->count());

        // Simulate the crash window: every claim committed, but the
        // process died before noteBatchProcessed() flipped the inbox
        // row, so the durable record still says 'received'.
        DB::table('suzu_traffic_batches')
            ->where('batch_hash', $batchKey)
            ->where('processor', TrafficBatchGuard::PROCESSOR_INBOX)
            ->update(['status' => TrafficBatchGuard::STATUS_RECEIVED, 'processed_at' => null]);
        $this->assertSame(TrafficBatchGuard::STATUS_RECEIVED, $this->inboxRow($batchKey)->status);

        // Age the whole family past the prune horizon and prune.
        DB::table('suzu_traffic_batches')
            ->where('batch_hash', $batchKey)
            ->update(['created_at' => time() - 200000]);
        $this->artisan('reset:log')->assertExitCode(0);

        // The fully claimed but unflipped family must survive intact —
        // deleting any claim here would let reconcile book that leg a
        // second time.
        $this->assertSame(
            4,
            DB::table('suzu_traffic_batches')->where('batch_hash', $batchKey)->count(),
            'a fully claimed but unflipped family must survive the prune intact'
        );

        // Reconcile only completes the bookkeeping flip; nothing is
        // booked a second time.
        $this->artisan('suzu:traffic-batch-reconcile', ['--older-than' => 0])->assertExitCode(0);
        $this->assertSame(TrafficBatchGuard::STATUS_PROCESSED, $this->inboxRow($batchKey)->status);
        $this->assertSame(700, (int) User::findOrFail($user->id)->u);
        $this->assertSame(300, (int) User::findOrFail($user->id)->d);
        $this->assertSame(700, (int) StatUser::where('user_id', $user->id)->firstOrFail()->u);
        $this->assertSame(700, (int) StatServer::where('server_id', $server->id)->firstOrFail()->u);
    }
    public function test_original_report_replay_uses_first_group_snapshot(): void
    {
        $server = $this->makeServer(['group_ids' => ['1']]);
        $moving = $this->makeUser(['group_id' => 1]);
        $staying = $this->makeUser(['group_id' => 1]);
        $data = [$moving->id => [100, 20], $staying->id => [200, 40]];
        $meta = $this->meta('group-replay');
        $controller = new ServerController();

        $this->assertSame(200, $controller->report($this->reportRequest($server, $data, $meta))->getStatusCode());
        $moving->updateQuietly(['group_id' => 2]);
        $this->assertSame(200, $controller->report($this->reportRequest($server, array_reverse($data, true), $meta))->getStatusCode());
        $this->assertSame(100, (int) $moving->fresh()->u);
        $this->assertSame(200, (int) $staying->fresh()->u);
        $row = $this->inboxRow(TrafficBatchGuard::keyForBatchId($server->id, $meta['batch_id'], 0));
        $this->assertSame($data, json_decode($row->payload, true)['data']);

        $data[$moving->id] = [101, 20];
        $this->assertSame(409, $controller->report($this->reportRequest($server, $data, $meta))->getStatusCode());
    }

    public function test_fully_filtered_first_report_still_reserves_original_identity(): void
    {
        $server = $this->makeServer(['group_ids' => ['1']]);
        $user = $this->makeUser(['group_id' => 2]);
        $meta = $this->meta('empty-filtered-batch');
        $data = [$user->id => [100, 0]];
        $controller = new ServerController();

        $this->assertSame(200, $controller->report($this->reportRequest($server, $data, $meta))->getStatusCode());
        $user->updateQuietly(['group_id' => 1]);
        $this->assertSame(200, $controller->report($this->reportRequest($server, $data, $meta))->getStatusCode());
        $this->assertSame(0, (int) $user->fresh()->u);
        $this->assertDatabaseCount('suzu_traffic_batches', 1);
        $this->assertSame(409, $controller->report($this->reportRequest($server, [$user->id => [200, 0]], $meta))->getStatusCode());
    }

    public function test_second_chunk_insert_failure_rolls_back_entire_receipt_and_retry_converges(): void
    {
        $server = $this->makeServer();
        $first = $this->makeUser();
        $last = $this->makeUser();
        $data = [$first->id => [100, 20]] + array_fill_keys(range(10000, 10998), [0, 0]) + [$last->id => [144, 0]];
        $meta = $this->meta('atomic-chunks');
        $secondKey = TrafficBatchGuard::keyForBatchId($server->id, $meta['batch_id'], 1);
        DB::unprepared("CREATE TRIGGER fail_second_receipt BEFORE INSERT ON suzu_traffic_batches
            WHEN NEW.processor = 'inbox' AND NEW.batch_hash = '{$secondKey}'
            BEGIN SELECT RAISE(ABORT, 'second chunk write failed'); END");

        $controller = new ServerController();
        $this->assertSame(500, $controller->report($this->reportRequest($server, $data, $meta))->getStatusCode());
        $this->assertDatabaseCount('suzu_traffic_batches', 0);
        $this->assertSame(0, (int) $first->fresh()->u);
        $this->assertSame(0, (int) $last->fresh()->u);
        DB::unprepared('DROP TRIGGER fail_second_receipt');

        foreach (range(1, 2) as $attempt) {
            $this->assertSame(200, $controller->report($this->reportRequest($server, $data, $meta))->getStatusCode());
            $this->assertSame(100, (int) $first->fresh()->u);
            $this->assertSame(144, (int) $last->fresh()->u);
        }
        $this->assertSame(2, DB::table('suzu_traffic_batches')->where('processor', 'inbox')->where('status', 'processed')->count());
        $this->assertSame(244, (int) StatServer::where('server_id', $server->id)->sum('u'));
    }

    public function test_no_id_content_dedup_warns_and_identified_samples_keep_distinct_identity(): void
    {
        $server = $this->makeServer();
        $user = $this->makeUser();
        $data = [$user->id => [100, 0]];
        $service = new UserService();
        $log = \Illuminate\Support\Facades\Log::spy();

        $service->trafficFetch($server, 'vless', $data);
        $service->trafficFetch($server, 'vless', $data);
        $this->assertSame(100, (int) $user->fresh()->u);
        $log->shouldHaveReceived('warning')->with('legacy traffic sample suppressed by content hash', [
            'dedup_mode' => 'legacy_content_hash',
            'server_id' => $server->id,
            'batch_hash' => TrafficBatchGuard::hashFor($server->id, 'vless', $data),
            'processor' => TrafficBatchGuard::PROCESSOR_FETCH,
        ])->once();

        $meta = $this->meta('sample-a');
        $service->trafficFetch($server, 'vless', $data, $meta);
        $service->trafficFetch($server, 'vless', $data, $meta);
        $service->trafficFetch($server, 'vless', $data, $this->meta('sample-b'));
        $this->assertSame(300, (int) $user->fresh()->u);
        $log->shouldHaveReceived('warning')->with('legacy traffic sample suppressed by content hash', \Mockery::any())->times(3);
    }

    public function test_reconcile_isolates_invalid_payload_and_redrives_only_missing_processors(): void
    {
        $server = $this->makeServer();
        $bad = TrafficBatchGuard::keyForBatchId($server->id, 'corrupt', 0);
        $good = TrafficBatchGuard::keyForBatchId($server->id, 'valid', 0);
        TrafficBatchGuard::receiveIdentified($bad, $server->id, null, '{');
        TrafficBatchGuard::receiveIdentified($good, $server->id, null, json_encode([
            'server' => ['id' => $server->id, 'rate' => 1],
            'data' => [1 => [100, 20]], 'protocol' => 'vless', 'timestamp' => time(),
        ]));
        TrafficBatchGuard::claimIdentified($good, $server->id, TrafficBatchGuard::PROCESSOR_FETCH, null);
        \Illuminate\Support\Facades\Queue::fake();

        $this->artisan('suzu:traffic-batch-reconcile', ['--older-than' => 0])->assertExitCode(1);
        $this->assertSame('unrecoverable', $this->inboxRow($bad)->status);
        $this->assertGreaterThan(time(), $this->inboxRow($good)->retry_at);
        \Illuminate\Support\Facades\Queue::assertNotPushed(TrafficFetchJob::class);
        \Illuminate\Support\Facades\Queue::assertPushed(StatUserJob::class, 1);
        \Illuminate\Support\Facades\Queue::assertPushed(StatServerJob::class, 1);

        $this->artisan('suzu:traffic-batch-reconcile', ['--older-than' => 0])->assertExitCode(1);
        \Illuminate\Support\Facades\Queue::assertPushed(StatUserJob::class, 1);
    }

    public function test_reconcile_defers_failed_dispatch_and_keeps_scanning(): void
    {
        $server = $this->makeServer();
        $payload = json_encode([
            'server' => ['id' => $server->id, 'rate' => 1],
            'data' => [1 => [100, 20]], 'protocol' => 'vless', 'timestamp' => time(),
        ]);
        $keys = array_map(fn ($id) => TrafficBatchGuard::keyForBatchId($server->id, $id, 0), ['fails-once', 'continues']);
        foreach ($keys as $key) {
            TrafficBatchGuard::receiveIdentified($key, $server->id, null, $payload);
        }
        DB::unprepared("CREATE TRIGGER fail_first_processor BEFORE INSERT ON suzu_traffic_batches
            WHEN NEW.processor = 'traffic_fetch' AND NEW.batch_hash = '{$keys[0]}'
            BEGIN SELECT RAISE(ABORT, 'simulated accounting write failure'); END");

        $this->artisan('suzu:traffic-batch-reconcile', ['--older-than' => 0])->assertExitCode(1);
        $this->assertSame('received', $this->inboxRow($keys[0])->status);
        $this->assertGreaterThan(time(), $this->inboxRow($keys[0])->retry_at);
        $this->assertSame('processed', $this->inboxRow($keys[1])->status);
        $this->artisan('suzu:traffic-batch-reconcile', ['--older-than' => 0])->assertExitCode(0);
        $this->assertSame(1, $this->inboxRow($keys[0])->retry_count);
        DB::unprepared('DROP TRIGGER fail_first_processor');
        DB::table('suzu_traffic_batches')->where('batch_hash', $keys[0])->update(['retry_at' => time() - 1]);
        $this->artisan('suzu:traffic-batch-reconcile', ['--older-than' => 0])->assertExitCode(0);
        $this->assertSame('processed', $this->inboxRow($keys[0])->status);
    }

}
