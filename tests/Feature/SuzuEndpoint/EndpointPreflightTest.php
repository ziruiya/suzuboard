<?php

namespace Tests\Feature\SuzuEndpoint;

use App\Models\Server;
use App\Models\SuzuEndpointBinding;
use App\Models\SuzuEndpointChange;
use App\Models\User;
use App\Services\SuzuEndpointContractService;
use App\Services\SuzuOriginPreflightService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * F01b negative coverage: a reachable TCP port must never be enough to
 * pass a preflight. Wrong ports fail at TCP; a live-but-wrong service
 * fails at the protocol layer.
 */
class EndpointPreflightTest extends TestCase
{
    use RefreshDatabase;

    private SuzuEndpointContractService $contracts;
    private SuzuOriginPreflightService $preflight;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contracts = app(SuzuEndpointContractService::class);
        $this->preflight = app(SuzuOriginPreflightService::class);

        $user = User::create([
            'email' => 'patrol@example.test',
            'password' => 'password',
            'uuid' => '11111111-2222-3333-4444-555555555555',
            'token' => 'patrol-token-for-tests',
            'balance' => 0, 'commission_balance' => 0, 'transfer_enable' => 0,
            'u' => 0, 'd' => 0, 'banned' => 0, 'is_admin' => 0, 'is_staff' => 0,
            'expired_at' => 0, 'remind_expire' => 1, 'remind_traffic' => 1,
            'created_at' => time(), 'updated_at' => time(),
        ]);
        config()->set('suzu_endpoint.probe.user_id', $user->id);
        // Force the PHP-native engine in tests.
        config()->set('suzu_endpoint.probe.singbox_binary', '/nonexistent/sing-box');
        config()->set('suzu_endpoint.probe.connect_timeout', 2);
        config()->set('suzu_endpoint.probe.io_timeout', 2);
    }

    private function makeWsNode(int $serverPort): Server
    {
        return Server::create([
            'name' => 'C组预检测试', 'type' => Server::TYPE_VLESS,
            'host' => 'c1.example.test', 'port' => $serverPort, 'server_port' => $serverPort,
            'rate' => '1', 'enabled' => true,
            'protocol_settings' => [
                'tls' => 1, 'network' => 'ws',
                'tls_settings' => ['server_name' => 'c1.example.test', 'allow_insecure' => false],
                'network_settings' => ['path' => '/SuzuWebSocket', 'headers' => ['Host' => 'c1.example.test']],
            ],
        ]);
    }

    public function test_preflight_fails_when_origin_port_is_closed(): void
    {
        $server = $this->makeWsNode(59998); // nothing listens here
        $contract = $this->contracts->snapshotFromServer($server)['contract'];

        $result = $this->preflight->run($contract, '127.0.0.1');

        $this->assertSame('failed', $result['overall']);
        $tcp = collect($result['checks'])->firstWhere('name', 'tcp_connect');
        $this->assertFalse($tcp['ok']);

        $binding = SuzuEndpointBinding::where('contract_id', $contract->id)->first();
        $this->assertSame('failed', $binding->health_status);
        $this->assertNotNull($binding->last_preflight);
        $this->assertSame(1, SuzuEndpointChange::where('contract_id', $contract->id)->where('action', 'preflight')->count());
    }

    public function test_open_tcp_port_with_wrong_service_still_fails(): void
    {
        // A plain TCP listener: the port is open, but it speaks neither
        // TLS nor VLESS — the preflight must fail at the TLS layer.
        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($listener, $errstr);
        $name = stream_socket_get_name($listener, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);

        try {
            $server = $this->makeWsNode($port);
            $contract = $this->contracts->snapshotFromServer($server)['contract'];

            $result = $this->preflight->run($contract, '127.0.0.1');

            $this->assertSame('failed', $result['overall']);
            $tcp = collect($result['checks'])->firstWhere('name', 'tcp_connect');
            $this->assertTrue($tcp['ok'], 'tcp layer should pass against the live listener');
            $tls = collect($result['checks'])->firstWhere('name', 'tls_handshake');
            $this->assertNotNull($tls);
            $this->assertFalse($tls['ok'], 'a non-TLS service must not pass the TLS check');
        } finally {
            fclose($listener);
        }
    }

    public function test_preflight_fails_on_contract_drift(): void
    {
        $server = $this->makeWsNode(59997);
        $contract = $this->contracts->snapshotFromServer($server)['contract'];

        $server->host = 'drifted.example.test';
        $server->save();

        $result = $this->preflight->run($contract, '127.0.0.1');
        $this->assertSame('failed', $result['overall']);
        $integrity = collect($result['checks'])->firstWhere('name', 'contract_integrity');
        $this->assertFalse($integrity['ok']);
    }

    public function test_preflight_for_trojan_grpc_fails_on_closed_port(): void
    {
        $server = Server::create([
            'name' => 'B组预检测试', 'type' => Server::TYPE_TROJAN,
            'host' => 'b1.example.test', 'port' => 59996, 'server_port' => 59996,
            'rate' => '1', 'enabled' => true,
            'protocol_settings' => [
                'tls' => 1, 'network' => 'grpc',
                'tls_settings' => ['server_name' => 'b1.example.test', 'allow_insecure' => false],
                'network_settings' => ['serviceName' => 'SuzuStream'],
            ],
        ]);
        $contract = $this->contracts->snapshotFromServer($server)['contract'];

        $result = $this->preflight->run($contract, '127.0.0.1');
        $this->assertSame('failed', $result['overall']);
    }
}
