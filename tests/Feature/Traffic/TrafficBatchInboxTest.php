<?php

namespace Tests\Feature\Traffic;

use App\Http\Controllers\V2\Server\ServerController;
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
}
