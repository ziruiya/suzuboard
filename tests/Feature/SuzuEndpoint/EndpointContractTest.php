<?php

namespace Tests\Feature\SuzuEndpoint;

use App\Models\Server;
use App\Models\SuzuEndpointBinding;
use App\Models\SuzuEndpointChange;
use App\Models\SuzuEndpointContract;
use App\Services\SuzuEndpointContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class EndpointContractTest extends TestCase
{
    use RefreshDatabase;

    private SuzuEndpointContractService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SuzuEndpointContractService::class);
    }

    private function makeRealityNode(array $overrides = []): Server
    {
        return Server::create(array_merge([
            'name' => 'A组测试①',
            'type' => Server::TYPE_VLESS,
            'host' => 'a1.example.test',
            'port' => 8443,
            'server_port' => 8443,
            'rate' => '1',
            'enabled' => true,
            'protocol_settings' => [
                'tls' => 2,
                'network' => 'tcp',
                'flow' => 'xtls-rprx-vision',
                'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
                'reality_settings' => [
                    'server_name' => 'www.icloud.com',
                    'server_port' => '443',
                    'public_key' => 'NiaYMCJi0BmseArZXhHUNo8jp0HX4bmE7KtNYcMwy2w',
                    'private_key' => 'DUMMY-PRIVATE-KEY-FOR-TESTS-ONLY-0000000000',
                    'short_id' => 'ab12cd34',
                    'allow_insecure' => false,
                ],
            ],
        ], $overrides));
    }

    public function test_snapshot_creates_contract_with_fingerprint_and_no_secrets(): void
    {
        $server = $this->makeRealityNode();
        $result = $this->service->snapshotFromServer($server);

        $this->assertTrue($result['created']);
        $this->assertFalse($result['alarm']);
        $contract = $result['contract'];
        $this->assertSame(1, $contract->version);
        $this->assertSame('active', $contract->status);
        $this->assertSame('a1.example.test', $contract->legacy_hostname);
        $this->assertSame(8443, $contract->client_port);
        $this->assertSame('vless', $contract->protocol);
        $this->assertSame('tcp', $contract->transport);
        $this->assertSame('www.icloud.com', $contract->sni);
        $this->assertSame('ab12cd34', $contract->short_id);
        $this->assertSame(
            hash('sha256', 'NiaYMCJi0BmseArZXhHUNo8jp0HX4bmE7KtNYcMwy2w'),
            $contract->reality_public_key_fingerprint
        );
        $this->assertSame(64, strlen($contract->contract_hash));

        // No private key material anywhere in the contract row.
        $raw = json_encode($contract->getAttributes());
        $this->assertStringNotContainsString('DUMMY-PRIVATE-KEY', $raw);
        $this->assertStringNotContainsString('private_key', $raw);

        // Binding auto-created, dns mode (client port == origin port).
        $binding = SuzuEndpointBinding::where('contract_id', $contract->id)->first();
        $this->assertNotNull($binding);
        $this->assertSame('dns', $binding->entry_mode);
        $this->assertSame(1, $binding->revision);

        // Audit row written.
        $this->assertSame(1, SuzuEndpointChange::where('contract_id', $contract->id)->where('action', 'snapshot')->count());
    }

    public function test_snapshot_marks_vx_entry_mode_when_ports_differ(): void
    {
        $server = $this->makeRealityNode(['port' => 2854, 'server_port' => 8443]);
        $result = $this->service->snapshotFromServer($server);
        $binding = SuzuEndpointBinding::where('contract_id', $result['contract']->id)->first();
        $this->assertSame('vx', $binding->entry_mode);
    }

    public function test_snapshot_is_idempotent_when_node_unchanged(): void
    {
        $server = $this->makeRealityNode();
        $first = $this->service->snapshotFromServer($server);
        $second = $this->service->snapshotFromServer($server->fresh());

        $this->assertFalse($second['created']);
        $this->assertFalse($second['alarm']);
        $this->assertSame($first['contract']->id, $second['contract']->id);
        $this->assertSame(1, SuzuEndpointContract::where('logical_node_id', $server->id)->count());
    }

    public function test_node_drift_supersedes_contract_and_raises_alarm(): void
    {
        $server = $this->makeRealityNode();
        $first = $this->service->snapshotFromServer($server);

        // Operator changes the frozen parameter on the live node.
        $server->port = 9443;
        $server->save();

        $second = $this->service->snapshotFromServer($server->fresh());
        $this->assertTrue($second['created']);
        $this->assertTrue($second['alarm']);
        $this->assertArrayHasKey('client_port', $second['drift']);
        $this->assertSame(2, $second['contract']->version);
        $this->assertSame('superseded', $first['contract']->fresh()->status);
        $this->assertSame('active', $second['contract']->status);
        $this->assertSame(
            1,
            SuzuEndpointChange::where('contract_id', $second['contract']->id)->where('action', 'supersede')->count()
        );
    }

    public function test_verify_integrity_detects_drift(): void
    {
        $server = $this->makeRealityNode();
        $contract = $this->service->snapshotFromServer($server)['contract'];

        $ok = $this->service->verifyIntegrity($contract);
        $this->assertTrue($ok['ok']);

        $server->host = 'moved.example.test';
        $server->save();

        $bad = $this->service->verifyIntegrity($contract);
        $this->assertFalse($bad['ok']);
        $this->assertArrayHasKey('legacy_hostname', $bad['drift']);
    }

    public function test_contract_is_immutable(): void
    {
        $server = $this->makeRealityNode();
        $contract = $this->service->snapshotFromServer($server)['contract'];

        $this->expectException(LogicException::class);
        $contract->legacy_hostname = 'tampered.example.test';
        $contract->save();
    }

    public function test_contract_cannot_be_deleted(): void
    {
        $server = $this->makeRealityNode();
        $contract = $this->service->snapshotFromServer($server)['contract'];

        $this->expectException(LogicException::class);
        $contract->delete();
    }

    public function test_ws_and_grpc_fields_are_captured(): void
    {
        $ws = Server::create([
            'name' => 'C组测试', 'type' => Server::TYPE_VLESS,
            'host' => 'c1.example.test', 'port' => 8444, 'server_port' => 8444,
            'rate' => '1', 'enabled' => true,
            'protocol_settings' => [
                'tls' => 1, 'network' => 'ws',
                'tls_settings' => ['server_name' => 'c1.example.test', 'allow_insecure' => false],
                'network_settings' => ['path' => '/SuzuWebSocket', 'headers' => ['Host' => 'c1.example.test']],
            ],
        ]);
        $cContract = $this->service->snapshotFromServer($ws)['contract'];
        $this->assertSame('/SuzuWebSocket', $cContract->ws_path);
        $this->assertSame('c1.example.test', $cContract->sni);
        $this->assertNull($cContract->reality_public_key_fingerprint);

        $grpc = Server::create([
            'name' => 'B组测试', 'type' => Server::TYPE_TROJAN,
            'host' => 'b1.example.test', 'port' => 8443, 'server_port' => 8443,
            'rate' => '1', 'enabled' => true,
            'protocol_settings' => [
                'tls' => 1, 'network' => 'grpc',
                'tls_settings' => ['server_name' => 'b1.example.test', 'allow_insecure' => false],
                'network_settings' => ['serviceName' => 'SuzuStream'],
            ],
        ]);
        $bContract = $this->service->snapshotFromServer($grpc)['contract'];
        $this->assertSame('SuzuStream', $bContract->grpc_service_name);
        $this->assertSame('trojan', $bContract->protocol);
        $this->assertSame('grpc', $bContract->transport);
    }

    public function test_range_endpoints_are_frozen_and_verified(): void
    {
        $server = $this->makeRealityNode(['port' => '08443 - 08445']);
        $original = $this->service->snapshotFromServer($server)['contract'];
        $this->assertSame('8443-8445', $original->fresh()->client_port_range);

        $server->update(['port' => '8443-8445']);
        $this->assertTrue($this->service->verifyIntegrity($original)['ok']);
        $this->assertFalse($this->service->snapshotFromServer($server)['created']);

        $server->update(['port' => '8443-8446']);
        $result = $this->service->verifyIntegrity($original);
        $this->assertFalse($result['ok']);
        $this->assertSame(['contract' => '8443-8445', 'live' => '8443-8446'], $result['drift']['client_port_range']);
        $next = $this->service->snapshotFromServer($server);
        $this->assertTrue($next['alarm']);
        $this->assertSame(2, $next['contract']->version);
    }

}
