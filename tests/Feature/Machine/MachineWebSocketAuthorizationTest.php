<?php

namespace Tests\Feature\Machine;

use App\Http\Controllers\V2\Admin\Server\MachineController;
use App\Services\DeviceStateService;
use App\Services\MachineAuthEpoch;
use App\Services\NodeRegistry;
use App\WebSocket\NodeWorker;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Workerman\Connection\TcpConnection;

class MachineWebSocketAuthorizationTest extends MachineTestCase
{
    protected function tearDown(): void
    {
        if (isset($this->vipServer, $this->vipMachine)) {
            NodeRegistry::remove($this->vipServer->id);
            NodeRegistry::removeMachine($this->vipMachine->id);
        }

        parent::tearDown();
    }

    public function test_disable_commits_epoch_and_revokes_existing_connection(): void
    {
        Redis::shouldReceive('get')->andReturn('0');
        [$worker, $conn] = $this->handshake();
        $conn->shouldReceive('destroy')->times(3);
        $conn->shouldNotReceive('send');

        $revocation = null;
        Redis::shouldReceive('eval')->once()->andReturnUsing(function ($script, $keys, $key, $epoch, $channel, $event) use (&$revocation) {
            $machine = $this->vipMachine->fresh();
            $this->assertFalse($machine->is_active);
            $this->assertSame(1, $machine->auth_epoch);
            $this->assertSame("machine:auth_epoch:{$machine->id}", $key);
            $this->assertSame('1', $epoch);
            $revocation = $event;
            return 1;
        });

        app(MachineController::class)->save(Request::create('/', 'POST', [
            'id' => $this->vipMachine->id,
            'name' => $this->vipMachine->name,
            'is_active' => false,
        ]));

        // Stale Redis cache and an undelivered event still deny both directions.
        $this->assertFalse(NodeRegistry::send($this->vipServer->id, 'sync.users', ['users' => []]));
        $this->assertPongRejected($worker, $conn);
        $worker->onRedisPush($revocation);
    }

    public function test_rotation_invalidates_old_epoch_even_if_redis_update_fails(): void
    {
        Redis::shouldReceive('get')->andReturn(false);
        [$worker, $conn] = $this->handshake();
        $conn->shouldReceive('destroy')->times(2);
        $conn->shouldNotReceive('send');
        Redis::shouldReceive('eval')->once()->andThrow(new \RuntimeException('Redis unavailable'));

        app(MachineController::class)->resetToken(Request::create('/', 'POST', ['id' => $this->vipMachine->id]));

        $machine = $this->vipMachine->fresh();
        $this->assertTrue($machine->is_active);
        $this->assertSame(1, $machine->auth_epoch);
        $this->assertNotSame($this->vipMachine->token, $machine->token);
        $this->assertFalse(NodeRegistry::sendMachine($machine->id, 'sync.nodes', ['nodes' => []]));
        $this->assertPongRejected($worker, $conn);

        NodeRegistry::removeMachine($machine->id);
        NodeRegistry::remove($this->vipServer->id);
        [, $fresh] = $this->handshake($machine->token, 1);
        $fresh->shouldNotReceive('destroy');
        $worker->onRedisPush(json_encode([
            'event' => MachineAuthEpoch::REVOKED_EVENT,
            'machine_id' => $machine->id,
            'data' => ['auth_epoch' => 1],
        ], JSON_THROW_ON_ERROR));
    }

    public function test_rollback_restores_credentials_and_epoch_without_publishing(): void
    {
        Redis::shouldReceive('eval')->never();
        DB::beginTransaction();
        try {
            $updated = MachineAuthEpoch::update($this->vipMachine->id, [
                'token' => 'temporary-test-token',
                'is_active' => false,
            ]);
            $this->assertSame(1, $updated->auth_epoch);
            $this->assertFalse($updated->is_active);
        } finally {
            DB::rollBack();
        }

        $machine = $this->vipMachine->fresh();
        $this->assertSame(0, $machine->auth_epoch);
        $this->assertTrue($machine->is_active);
        $this->assertSame($this->vipMachine->token, $machine->token);
    }

    public function test_failed_business_update_keeps_epoch_and_token(): void
    {
        Redis::shouldReceive('eval')->never();

        try {
            MachineAuthEpoch::update($this->vipMachine->id, [
                'is_active' => null,
                'token' => 'uncommitted-test-token',
            ]);
            $this->fail('Expected the database to reject a null is_active');
        } catch (QueryException $e) {
            $this->assertStringContainsString('is_active', $e->getMessage());
        }

        $machine = $this->vipMachine->fresh();
        $this->assertSame(0, $machine->auth_epoch);
        $this->assertTrue($machine->is_active);
        $this->assertSame($this->vipMachine->token, $machine->token);
    }

    public function test_enabling_increments_epoch_once_and_repeated_save_keeps_it(): void
    {
        Redis::shouldReceive('eval')->twice()->andReturn(1);
        $controller = app(MachineController::class);
        foreach ([false, true, true] as $active) {
            $controller->save(Request::create('/', 'POST', [
                'id' => $this->vipMachine->id,
                'name' => $this->vipMachine->name,
                'is_active' => $active,
            ]));
        }

        $machine = $this->vipMachine->fresh();
        $this->assertTrue($machine->is_active);
        $this->assertSame(2, $machine->auth_epoch);
    }

    private function handshake(?string $token = null, int $epoch = 0): array
    {
        $service = Mockery::mock(DeviceStateService::class);
        $service->shouldReceive('clearAllNodeDevices')->with($this->vipServer->id)->once()->andReturn([]);
        $this->app->instance(DeviceStateService::class, $service);

        $worker = new NodeWorker('127.0.0.1', 0);
        $conn = Mockery::mock(TcpConnection::class);
        $conn->shouldReceive('getRemoteIp')->andReturn('127.0.0.1');
        $worker->onWebSocketConnect($conn, '/?' . http_build_query([
            'machine_id' => $this->vipMachine->id,
            'token' => $token ?? $this->vipMachine->token,
        ]));

        $this->assertSame($epoch, $conn->machineAuthEpoch);
        $this->assertSame($conn, NodeRegistry::get($this->vipServer->id));
        return [$worker, $conn];
    }

    private function assertPongRejected(NodeWorker $worker, TcpConnection $conn): void
    {
        $key = "node_ws_alive:{$this->vipServer->id}";
        Cache::forget($key);
        $worker->onMessage($conn, '{"event":"pong","data":{}}');
        $this->assertNull(Cache::get($key));
    }
}
