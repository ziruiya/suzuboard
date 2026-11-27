<?php

namespace Tests\Feature\Machine;

use App\Services\DevicePushDelivery;
use App\Services\DeviceStateService;
use App\Services\NodeRegistry;
use App\Services\NodeSyncService;
use App\WebSocket\NodeEventHandlers;
use App\WebSocket\NodeWorker;
use Illuminate\Support\Facades\Redis;
use Mockery;
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
        Redis::shouldReceive('eval')->once()->andReturn(null);
        Redis::shouldReceive('get')->with("node:state_revision:{$nodeId}:config")->once()->andReturn('2');
        Redis::shouldReceive('get')->with("node:state_revision:{$nodeId}:users")->once()->andReturn('5');
        $conn = Mockery::mock(TcpConnection::class);
        $frames = [];
        $conn->shouldReceive('send')->twice()->andReturnUsing(function (string $payload) use (&$frames) {
            $frames[] = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            return true;
        });
        NodeRegistry::add($nodeId, $conn);

        NodeEventHandlers::pushFullSync($this->vipServer);

        $this->assertSame(['sync.config', 'sync.users'], array_column($frames, 'event'));
        $this->assertSame(2, $frames[0]['data']['state_revision']);
        $this->assertSame(5, $frames[1]['data']['state_revision']);
        $this->assertArrayHasKey('config', $frames[0]['data']);
        $this->assertArrayHasKey('users', $frames[1]['data']);
        $this->assertFalse(NodeRegistry::send($nodeId, 'sync.user.delta', [
            'action' => 'remove',
            'users' => [],
            'base_revision' => 3,
            'state_revision' => 4,
        ]));
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

    public function test_publish_keeps_revisions_in_order_with_independent_domains(): void
    {
        $socket = $this->useDedicatedRedis();
        $channel = config('database.redis.options.prefix') . 'node:push';
        $subscriber = stream_socket_client('unix://' . $socket, timeout: 2);
        stream_set_timeout($subscriber, 2);
        fwrite($subscriber, "SUBSCRIBE {$channel}\r\n");
        $this->assertSame(['subscribe', $channel, 1], $this->readPubSubFrame($subscriber));

        try {
            $nodeId = $this->vipServer->id;
            NodeSyncService::push($nodeId, 'sync.users', ['users' => []]);
            NodeSyncService::push($nodeId, 'sync.config', ['config' => ['server_port' => 443]]);
            NodeSyncService::push($nodeId, 'sync.user.delta', ['action' => 'remove', 'users' => [['id' => 17]]]);
            NodeSyncService::push($nodeId, 'sync.config', ['config' => ['server_port' => 444]]);
            NodeSyncService::push($nodeId, 'sync.user.delta', ['action' => 'add', 'users' => [['id' => 19]]]);

            $frames = [];
            for ($i = 0; $i < 5; $i++) {
                [$type, $receivedChannel, $payload] = $this->readPubSubFrame($subscriber);
                $this->assertSame('message', $type);
                $this->assertSame($channel, $receivedChannel);
                $frames[] = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertSame(['sync.users', 'sync.config', 'sync.user.delta', 'sync.config', 'sync.user.delta'], array_column($frames, 'event'));
            $this->assertSame(array_fill(0, 5, $nodeId), array_column($frames, 'node_id'));
            $data = array_column($frames, 'data');
            $this->assertSame([1, 1, 2, 2, 3], array_column($data, 'state_revision'));
            $this->assertSame($data[0]['state_revision'], $data[2]['base_revision']);
            $this->assertSame($data[2]['state_revision'], $data[4]['base_revision']);
            $this->assertSame([], $data[0]['users']);
            $this->assertSame('remove', $data[2]['action']);
            $this->assertSame([['id' => 17]], $data[2]['users']);
            $this->assertSame(2, NodeSyncService::currentRevision($nodeId, 'sync.config'));
            $this->assertSame(3, NodeSyncService::currentRevision($nodeId, 'sync.users'));
            $this->assertSame(0, NodeSyncService::currentRevision($nodeId + 1, 'sync.users'));
        } finally {
            fclose($subscriber);
        }
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

    private function readPubSubFrame($stream): array
    {
        $this->assertSame("*3\r\n", fgets($stream));
        $frame = [];
        for ($i = 0; $i < 3; $i++) {
            $header = fgets($stream);
            $this->assertIsString($header);
            if ($header[0] === ':') {
                $frame[] = (int) substr($header, 1);
            } else {
                $this->assertSame('$', $header[0]);
                $length = (int) substr($header, 1);
                $bytes = stream_get_contents($stream, $length + 2);
                $frame[] = substr($bytes, 0, $length);
            }
        }
        return $frame;
    }
}
