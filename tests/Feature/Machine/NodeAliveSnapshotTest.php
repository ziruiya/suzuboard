<?php

namespace Tests\Feature\Machine;

use App\WebSocket\NodeWorker;
use Illuminate\Support\Facades\Redis;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NodeRedisFake;
use Workerman\Connection\TcpConnection;

class NodeAliveSnapshotTest extends MachineTestCase
{
    public static function transports(): iterable
    {
        yield 'REST report' => ['report'];
        yield 'REST alive' => ['alive'];
        yield 'WebSocket' => ['ws'];
    }

    #[DataProvider('transports')]
    public function test_complete_reports_replace_and_unmarked_reports_only_refresh(string $transport): void
    {
        $redis = new NodeRedisFake();
        Redis::swap($redis);
        $nodeId = $this->vipServer->id;
        $one = $this->makeUser();
        $two = $this->makeUser();

        $this->report($transport, [
            $one->id => ['192.0.2.10', '192.0.2.11'],
            $two->id => ['192.0.2.12'],
        ], true);
        $prefix = config('database.redis.options.prefix', '') . 'user_devices:';
        $redis->hashes[$prefix . $two->id]['999:192.0.2.20'] = (string) time();

        $this->report($transport, [$one->id => ['192.0.2.11:443']], null);
        $this->report($transport, [$one->id => []], null);
        $this->assertArrayHasKey("{$nodeId}:192.0.2.10", $redis->hgetall('user_devices:' . $one->id));
        $this->assertArrayHasKey("{$nodeId}:192.0.2.12", $redis->hgetall('user_devices:' . $two->id));

        $this->report($transport, [$one->id => ['192.0.2.11:443']], true);
        $this->assertSame(["{$nodeId}:192.0.2.11"], array_keys($redis->hgetall('user_devices:' . $one->id)));
        $this->assertSame(['999:192.0.2.20'], array_keys($redis->hgetall('user_devices:' . $two->id)));
        $this->assertSame(1, $one->fresh()->online_count);

        $this->report($transport, [], true);
        $this->assertSame([], $redis->hgetall('user_devices:' . $one->id));
        $this->assertSame(['999:192.0.2.20'], array_keys($redis->hgetall('user_devices:' . $two->id)));
        $this->assertSame(0, $one->fresh()->online_count);
    }

    private function report(string $transport, array $users, ?bool $complete): void
    {
        $marker = $complete === null ? [] : ['alive_complete' => $complete];
        if ($transport === 'ws') {
            $conn = Mockery::mock(TcpConnection::class);
            $conn->nodeId = $this->vipServer->id;
            (new NodeWorker('127.0.0.1', 0))->onMessage($conn, json_encode([
                'event' => 'report.devices', 'data' => ['devices' => $users] + $marker,
            ], JSON_THROW_ON_ERROR));
            return;
        }

        $query = http_build_query([
            'machine_id' => $this->vipMachine->id,
            'node_id' => $this->vipServer->id,
            'token' => $this->vipMachine->token,
        ]);
        $data = $transport === 'alive' && $complete === null ? $users : ['alive' => $users] + $marker;
        $this->postJson("/api/v2/server/{$transport}?{$query}", $data)->assertOk();
    }
}
