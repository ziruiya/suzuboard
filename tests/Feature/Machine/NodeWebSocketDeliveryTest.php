<?php

namespace Tests\Feature\Machine;

use App\Services\DevicePushDelivery;
use App\Services\DeviceStateService;
use App\Services\NodeRegistry;
use App\Services\NodeSyncService;
use App\Models\User;
use App\WebSocket\NodeEventHandlers;
use App\WebSocket\NodeWorker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\DataProvider;
use Mockery;
use Tests\Support\NodeRedisFake;
use Workerman\Connection\TcpConnection;

class NodeWebSocketDeliveryTest extends MachineTestCase
{
    protected function tearDown(): void
    {
        if (isset($this->vipServer, $this->vipMachine)) {
            NodeRegistry::remove($this->vipServer->id);
            NodeRegistry::removeMachine($this->vipMachine->id);
        }

        parent::tearDown();
    }

    public function test_send_reports_actual_socket_result_and_rejects_invalid_json(): void
    {
        $nodeId = $this->vipServer->id;
        $conn = Mockery::mock(TcpConnection::class);
        $conn->shouldReceive('send')->times(3)->andReturn(false, null, true);
        NodeRegistry::add($nodeId, $conn);

        $this->assertFalse(NodeRegistry::send($nodeId, 'sync.users', ['users' => ["\xff"]]));
        $this->assertFalse(NodeRegistry::send($nodeId, 'sync.users', ['users' => []]));
        $this->assertFalse(NodeRegistry::send($nodeId, 'sync.users', ['users' => []]));
        $this->assertTrue(NodeRegistry::send($nodeId, 'sync.users', ['users' => []]));

        $machineConn = Mockery::mock(TcpConnection::class);
        $machineConn->machineId = $this->vipMachine->id;
        $machineConn->machineAuthEpoch = 0;
        Redis::shouldReceive('get')->andReturn('0');
        NodeRegistry::addMachine($this->vipMachine->id, $machineConn);
        $machineConn->shouldReceive('send')->twice()->andReturn(false, true);

        $this->assertFalse(NodeRegistry::sendMachine($this->vipMachine->id, 'sync.nodes', ['nodes' => ["\xff"]]));
        $this->assertFalse(NodeRegistry::sendMachine($this->vipMachine->id, 'sync.nodes', ['nodes' => []]));
        $this->assertTrue(NodeRegistry::sendMachine($this->vipMachine->id, 'sync.nodes', ['nodes' => []]));
    }

    public function test_initial_sync_uses_current_revisions_and_suppresses_older_queued_events(): void
    {
        $nodeId = $this->vipServer->id;
        $redis = new NodeRedisFake();
        $redis->values = ["node:state_revision:{$nodeId}:config" => '2', "node:state_revision:{$nodeId}:users" => '5'];
        Redis::swap($redis);
        $conn = Mockery::mock(TcpConnection::class);
        $frames = [];
        $conn->shouldReceive('send')->twice()->andReturnUsing(function (string $payload) use (&$frames) {
            $frames[] = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            return true;
        });
        NodeRegistry::add($nodeId, $conn);

        NodeEventHandlers::pushFullSync($this->vipServer);

        $this->assertSame(['sync.config', 'sync.users'], array_column($frames, 'event'));
        $this->assertSame(3, $frames[0]['data']['state_revision']);
        $this->assertSame(6, $frames[1]['data']['state_revision']);
        $this->assertArrayHasKey('config', $frames[0]['data']);
        $this->assertArrayHasKey('users', $frames[1]['data']);
        $this->assertFalse(NodeRegistry::send($nodeId, 'sync.user.delta', [
            'action' => 'remove',
            'users' => [],
            'base_revision' => 3,
            'state_revision' => 4,
        ]));
    }

    public static function unconfirmedSocketResults(): array
    {
        return ['false' => [false], 'null' => [null]];
    }

    #[DataProvider('unconfirmedSocketResults')]
    public function test_unconfirmed_send_preserves_watermark_until_same_revision_is_written(?bool $unconfirmed): void
    {
        $nodeId = $this->vipServer->id;
        $conn = Mockery::mock(TcpConnection::class);
        Redis::swap(new NodeRedisFake());
        $conn->shouldReceive('send')->twice()->andReturn($unconfirmed, true);
        $conn->shouldReceive('getSendBufferQueueSize')->andReturn(0);
        NodeRegistry::add($nodeId, $conn);

        $data = ['config' => [], 'state_revision' => 7];
        $key = "{$nodeId}:config";
        $this->assertFalse(NodeRegistry::send($nodeId, 'sync.config', $data));
        $this->assertArrayNotHasKey($key, $conn->stateRevisions ?? []);
        $this->assertTrue(NodeRegistry::send($nodeId, 'sync.config', $data));
        $this->assertSame(7, $conn->stateRevisions[$key]);
        $this->assertFalse(NodeRegistry::send($nodeId, 'sync.config', ['config' => [], 'state_revision' => 6]));
        $this->assertSame(7, $conn->stateRevisions[$key]);
    }

    public function test_buffered_snapshots_confirm_only_after_socket_drain(): void
    {
        $redis = new NodeRedisFake();
        Redis::swap($redis);
        $nodeId = $this->vipServer->id;
        $conn = Mockery::mock(TcpConnection::class);
        $conn->shouldReceive('send')->twice()->andReturnNull();
        $conn->shouldReceive('getSendBufferQueueSize')->twice()->andReturn(4096);
        NodeRegistry::add($nodeId, $conn);

        $this->assertFalse(NodeRegistry::send($nodeId, 'sync.config', ['config' => [], 'state_revision' => 2]));
        $this->assertFalse(NodeRegistry::send($nodeId, 'sync.users', ['users' => [], 'state_revision' => 3]));
        $this->assertFalse(NodeRegistry::send($nodeId, 'sync.config', ['config' => [], 'state_revision' => 1]));
        $this->assertFalse($redis->hget('node:state_delivered:config', $nodeId));
        $this->assertFalse($redis->hget('node:state_delivered:users', $nodeId));

        ($conn->onBufferDrain)($conn);
        $this->assertSame('2', $redis->hget('node:state_delivered:config', $nodeId));
        $this->assertSame('3', $redis->hget('node:state_delivered:users', $nodeId));
    }

    public function test_dropped_publish_and_socket_failure_reconcile_both_domains(): void
    {
        $redis = new NodeRedisFake();
        Redis::swap($redis);
        $nodeId = $this->vipServer->id;
        Cache::put("node_ws_alive:{$nodeId}", true);
        $frames = [];
        $conn = Mockery::mock(TcpConnection::class);
        $conn->shouldReceive('getSendBufferQueueSize')->andReturn(0);
        $conn->shouldReceive('send')->times(6)->andReturnUsing(function (string $payload) use (&$frames) {
            $frames[] = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            return count($frames) !== 3 && count($frames) !== 4 ? true : null;
        });
        NodeRegistry::add($nodeId, $conn);
        NodeEventHandlers::pushFullSync($this->vipServer);

        $this->vipServer->updateQuietly(['server_port' => 445]);
        $this->makeAvailableUser();
        NodeSyncService::notifyFullSync($nodeId);
        $this->assertCount(2, $redis->published);
        $redis->published = []; // Redis reports subscribers, but the Worker never sees the messages.

        $worker = new NodeWorker('127.0.0.1', 0);
        $this->artisan('suzu:node-resync')->assertExitCode(0);
        $this->assertCount(2, $redis->published);
        foreach ($redis->published as $message) {
            $worker->onRedisPush($message);
        }
        foreach (['config', 'users'] as $domain) {
            $this->assertSame('1', $redis->hget("node:state_delivered:{$domain}", $nodeId));
        }
        $this->assertSame('2', $redis->hget(NodeSyncService::CONFIG_RESYNC_PENDING_KEY, $nodeId));

        $redis->published = [];
        $this->artisan('suzu:node-resync')->assertExitCode(0);
        $this->assertCount(2, $redis->published);
        foreach ($redis->published as $message) {
            $worker->onRedisPush($message);
        }
        foreach (['config', 'users'] as $domain) {
            $this->assertSame('2', $redis->hget("node:state_delivered:{$domain}", $nodeId));
        }
        $this->assertFalse($redis->hget(NodeSyncService::CONFIG_RESYNC_PENDING_KEY, $nodeId));
        $this->assertSame([1, 1, 2, 2, 2, 2], array_column(array_column($frames, 'data'), 'state_revision'));

        $redis->published = [];
        $this->artisan('suzu:node-resync')->assertExitCode(0);
        $this->assertSame([], $redis->published);
    }

    public function test_periodic_scan_recovers_changes_with_no_usable_notification(): void
    {
        $redis = new NodeRedisFake();
        Redis::swap($redis);
        $nodeId = $this->vipServer->id;
        Cache::put("node_ws_alive:{$nodeId}", true);

        $conn = Mockery::mock(TcpConnection::class);
        $frames = [];
        $conn->shouldReceive('send')->times(4)->andReturnUsing(function (string $payload) use (&$frames) {
            $frames[] = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            return true;
        });
        NodeRegistry::add($nodeId, $conn);
        NodeEventHandlers::pushFullSync($this->vipServer);
        $frames = [];
        $worker = new NodeWorker('127.0.0.1', 0);
        $redis->subscribers = 0;
        $this->vipServer->updateQuietly(['server_port' => 452]);
        $user = $this->makeAvailableUser();
        NodeSyncService::notifyFullSync($nodeId);
        $this->assertSame([], $redis->published);

        // Simulate a crash after the later commit, before its notifier ran.
        DB::transaction(function () use ($user) {
            $this->vipServer->updateQuietly(['server_port' => 453]);
            $user->updateQuietly(['speed_limit' => 25]);
        });
        $redis->subscribers = 1;
        $this->artisan('suzu:node-resync')->assertExitCode(0);
        $this->assertCount(2, $redis->published);
        $this->assertSame('1', $redis->hget('node:state_delivered:config', $nodeId));
        $this->assertSame('1', $redis->hget('node:state_delivered:users', $nodeId));

        // The Worker is listening again. Dispatch this cycle's snapshots.
        foreach ($redis->published as $message) {
            $worker->onRedisPush($message);
        }
        $this->assertSame(453, $frames[0]['data']['config']['server_port']);
        $this->assertSame(25, $frames[1]['data']['users'][0]['speed_limit']);
        $this->assertSame('3', $redis->hget('node:state_delivered:config', $nodeId));
        $this->assertSame('3', $redis->hget('node:state_delivered:users', $nodeId));
    }

    public function test_delta_with_missing_base_is_replaced_by_full_snapshot(): void
    {
        $redis = new NodeRedisFake();
        Redis::swap($redis);
        $nodeId = $this->vipServer->id;
        Cache::put("node_ws_alive:{$nodeId}", true);

        $frames = [];
        $conn = Mockery::mock(TcpConnection::class);
        $conn->shouldReceive('send')->times(3)->andReturnUsing(function (string $payload) use (&$frames) {
            $frames[] = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            return true;
        });
        NodeRegistry::add($nodeId, $conn);
        NodeEventHandlers::pushFullSync($this->vipServer);

        $user = $this->makeAvailableUser();
        NodeSyncService::notifyUserChanged($user);
        $user->updateQuietly(['speed_limit' => 43]);
        NodeSyncService::notifyUserChanged($user);

        $this->assertCount(2, $redis->published);
        $second = json_decode($redis->published[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('sync.user.delta', $second['event']);
        $this->assertSame(2, $second['data']['base_revision']);
        (new NodeWorker('127.0.0.1', 0))->onRedisPush($redis->published[1]);
        $this->assertSame(['sync.config', 'sync.users', 'sync.users'], array_column($frames, 'event'));
        $this->assertSame(43, $frames[2]['data']['users'][0]['speed_limit']);
        $this->assertSame('3', $redis->hget('node:state_delivered:users', $nodeId));
    }

    public function test_worker_offline_changes_and_redis_flush_converge_on_reconnect(): void
    {
        $redis = new NodeRedisFake();
        Redis::swap($redis);
        $nodeId = $this->vipServer->id;
        NodeSyncService::notifyFullSync($nodeId);
        $this->vipServer->updateQuietly(['server_port' => 446]);
        $user = $this->makeAvailableUser();
        NodeSyncService::notifyFullSync($nodeId);
        $this->vipServer->updateQuietly(['server_port' => 447]);
        $user->updateQuietly(['speed_limit' => 99]);
        NodeSyncService::notifyFullSync($nodeId);

        $this->assertSame([], $redis->published);
        $this->assertFalse($redis->hget('node:state_delivered:config', $nodeId));
        $this->assertFalse($redis->hget('node:state_delivered:users', $nodeId));
        $redis = new NodeRedisFake(); // Lose every volatile key.
        Redis::swap($redis);

        $frames = [];
        $conn = Mockery::mock(TcpConnection::class);
        $conn->shouldReceive('send')->twice()->andReturnUsing(function (string $payload) use (&$frames) {
            $frames[] = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            return true;
        });
        NodeRegistry::add($nodeId, $conn);
        NodeEventHandlers::pushFullSync($this->vipServer);
        $this->assertSame(['sync.config', 'sync.users'], array_column($frames, 'event'));
        $this->assertSame([3, 3], array_column(array_column($frames, 'data'), 'state_revision'));
        $this->assertSame(447, $frames[0]['data']['config']['server_port']);
        $this->assertSame(99, $frames[1]['data']['users'][0]['speed_limit']);
        foreach (['config', 'users'] as $domain) {
            $this->assertSame('3', $redis->get("node:state_revision:{$nodeId}:{$domain}"));
            $this->assertSame('3', $redis->hget("node:state_delivered:{$domain}", $nodeId));
        }
    }

    public function test_device_failure_remains_pending_until_write_then_report_confirms_it(): void
    {
        $this->useDedicatedRedis();
        $nodeId = $this->vipServer->id;
        $worker = new NodeWorker('127.0.0.1', 0);
        $conn = Mockery::mock(TcpConnection::class);
        $conn->nodeId = $nodeId;
        $conn->shouldReceive('send')->twice()->andReturn(false, true);
        NodeRegistry::add($nodeId, $conn);

        $worker->onMessage($conn, '{"event":"report.devices","data":{"devices":{}}}');
        $this->assertDelivery($nodeId, 'pending', 0, true);

        $service = app(DeviceStateService::class);
        NodeEventHandlers::pushDeviceStateToNode($nodeId, $service);
        $this->assertDelivery($nodeId, 'pending', 1, true);

        NodeEventHandlers::pushDeviceStateToNode($nodeId, $service);
        $this->assertDelivery($nodeId, 'awaiting_confirmation', 2, false);

        $worker->onMessage($conn, '{"event":"report.devices","data":{"devices":{}}}');
        $this->assertDelivery($nodeId, 'applied', 2, true);
    }

    public function test_new_device_work_during_a_send_is_not_removed_by_its_completion(): void
    {
        $this->useDedicatedRedis();
        $nodeId = $this->vipServer->id;
        DevicePushDelivery::queue($nodeId);
        $this->assertTrue(DevicePushDelivery::attempt($nodeId, function () use ($nodeId) {
            DevicePushDelivery::queue($nodeId);
            return true;
        }));
        $this->assertDelivery($nodeId, 'pending', 1, true);

        $this->assertTrue(DevicePushDelivery::attempt($nodeId, fn () => true));
        $this->assertDelivery($nodeId, 'awaiting_confirmation', 2, false);
        DevicePushDelivery::retryUnconfirmed($nodeId);
        $this->assertDelivery($nodeId, 'pending', 2, true);
    }

    public function test_rest_and_socket_share_committed_revisions_and_delta_base(): void
    {
        $redis = new NodeRedisFake();
        Redis::swap($redis);
        $nodeId = $this->vipServer->id;
        Cache::put("node_ws_alive:{$nodeId}", true);
        $worker = new NodeWorker('127.0.0.1', 0);
        $frames = [];
        $conn = Mockery::mock(TcpConnection::class);
        $conn->shouldReceive('send')->times(4)->andReturnUsing(function (string $payload) use (&$frames) {
            $frames[] = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            return true;
        });
        NodeRegistry::add($nodeId, $conn);
        NodeEventHandlers::pushFullSync($this->vipServer);

        $this->assertSame($this->pull('config')['state_revision'], $frames[0]['data']['state_revision']);
        $this->assertSame($this->pull('user')['state_revision'], $frames[1]['data']['state_revision']);

        DB::beginTransaction();
        $this->vipServer->updateQuietly(['server_port' => 448]);
        $rolledBack = $this->makeAvailableUser();
        NodeSyncService::notifyConfigUpdated($nodeId);
        NodeSyncService::notifyUserChanged($rolledBack);
        $this->assertSame([], $redis->published);
        $this->assertSame('1', $redis->get("node:state_revision:{$nodeId}:config"));
        $this->assertSame('1', $redis->get("node:state_revision:{$nodeId}:users"));
        try {
            NodeSyncService::snapshot($nodeId, 'sync.config');
            $this->fail('An active write transaction must not expose a snapshot');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('committed database view', $e->getMessage());
        } finally {
            DB::rollBack();
        }
        $this->assertSame([], $redis->published);
        $this->assertSame(1, $this->pull('config')['state_revision']);
        $this->assertSame(1, $this->pull('user')['state_revision']);

        DB::transaction(function () use ($nodeId, $redis) {
            $this->vipServer->updateQuietly(['server_port' => 449]);
            $user = $this->makeAvailableUser();
            NodeSyncService::notifyConfigUpdated($nodeId);
            NodeSyncService::notifyUserChanged($user);
            $this->assertSame([], $redis->published);
        });
        foreach ($redis->published as $message) {
            $worker->onRedisPush($message);
        }

        $config = $this->pull('config');
        $users = $this->pull('user');
        $this->assertSame(['sync.config', 'sync.users', 'sync.config', 'sync.user.delta'], array_column($frames, 'event'));
        $this->assertSame(2, $config['state_revision']);
        $this->assertSame($config['state_revision'], $frames[2]['data']['state_revision']);
        $this->assertSame($config['server_port'], $frames[2]['data']['config']['server_port']);
        $this->assertSame(2, $users['state_revision']);
        $this->assertSame($users['state_revision'], $frames[3]['data']['state_revision']);
        $this->assertSame($frames[1]['data']['state_revision'], $frames[3]['data']['base_revision']);
        $this->assertSame($users['users'], $frames[3]['data']['users']);
    }

    public function test_overlapping_post_commit_publishers_cannot_reorder_revisions(): void
    {
        $redis = new NodeRedisFake();
        Redis::swap($redis);
        $nodeId = $this->vipServer->id;
        Cache::put("node_ws_alive:{$nodeId}", true);
        $first = $this->pull('config');

        $redis->beforePublish = function () use ($nodeId) {
            $intermediate = $this->pull('config');
            $this->assertSame(2, $intermediate['state_revision']);
            $this->assertSame(450, $intermediate['server_port']);
            DB::transaction(function () use ($nodeId) {
                $this->vipServer->updateQuietly(['server_port' => 451]);
                NodeSyncService::notifyConfigUpdated($nodeId);
            });
        };

        DB::transaction(function () use ($nodeId) {
            $this->vipServer->updateQuietly(['server_port' => 450]);
            NodeSyncService::notifyConfigUpdated($nodeId);
        });

        $this->assertCount(1, $redis->published);
        $latest = json_decode($redis->published[0], true, flags: JSON_THROW_ON_ERROR)['data'];
        $this->assertSame($first['state_revision'] + 2, $latest['state_revision']);
        $this->assertSame(451, $latest['config']['server_port']);
        $this->assertSame($latest['state_revision'], $this->pull('config')['state_revision']);
    }

    private function makeAvailableUser(): User
    {
        return User::withoutEvents(fn () => $this->makeUser([
            'group_id' => $this->vipGroup->id,
            'transfer_enable' => 100000,
            'expired_at' => time() + 3600,
        ]));
    }

    private function pull(string $path): array
    {
        return $this->getJson("/api/v2/server/{$path}?" . http_build_query([
            'machine_id' => $this->vipMachine->id,
            'node_id' => $this->vipServer->id,
            'token' => $this->vipMachine->token,
        ]))->assertOk()->json();
    }

    private function useDedicatedRedis(): string
    {
        $socket = getenv('BATCH1B_REDIS_TEST_SOCKET');
        if (!$socket || !is_readable($socket) || filetype($socket) !== 'socket') {
            $this->markTestSkipped('Set BATCH1B_REDIS_TEST_SOCKET to a dedicated local Redis Unix socket.');
        }

        config([
            'database.redis.options.prefix' => 'test_b1b_' . bin2hex(random_bytes(8)) . ':',
            'database.redis.default' => [
                'host' => $socket,
                'port' => 0,
                'database' => 0,
                'persistent' => false,
            ],
        ]);

        // The manager may already have captured config during application boot.
        Redis::swap(new \Illuminate\Redis\RedisManager($this->app, 'phpredis', config('database.redis')));
        return $socket;
    }

    private function assertDelivery(int $nodeId, string $status, int $attempts, bool $pending): void
    {
        $delivery = DevicePushDelivery::get($nodeId);
        $this->assertSame($status, $delivery['status']);
        $this->assertSame($attempts, $delivery['attempts']);
        $this->assertSame($pending, $delivery['pending']);
        $this->assertGreaterThan(0, $delivery['updated_at']);
    }

}
