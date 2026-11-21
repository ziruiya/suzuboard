<?php

namespace Tests\Unit\Services;

use App\Exceptions\SuzuEndpointException;
use App\Models\Server;
use App\Models\SuzuEndpointContract;
use App\Services\Endpoint\SingBoxProbe;
use PHPUnit\Framework\TestCase;

/**
 * The sing-box probe must build faithful client configs from a contract:
 * Reality material comes from the live node (checked against the frozen
 * fingerprint), the frozen transport parameters come from the contract.
 */
class SingBoxProbeConfigTest extends TestCase
{
    private function realityServer(string $publicKey): Server
    {
        $server = new Server();
        $server->id = 26;
        $server->type = Server::TYPE_VLESS;
        $server->host = 'vx.example.test';
        $server->port = 2854;
        $server->server_port = 8443;
        $server->protocol_settings = [
            'tls' => 2,
            'network' => 'tcp',
            'flow' => 'xtls-rprx-vision',
            'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
            'reality_settings' => [
                'server_name' => 'www.icloud.com',
                'server_port' => '443',
                'public_key' => $publicKey,
                'private_key' => 'not-used-by-probe',
                'short_id' => 'ab12cd34',
                'allow_insecure' => false,
            ],
        ];
        return $server;
    }

    private function realityContract(string $publicKey): SuzuEndpointContract
    {
        $contract = new SuzuEndpointContract();
        $contract->logical_node_id = 26;
        $contract->legacy_hostname = 'vx.example.test';
        $contract->client_port = 2854;
        $contract->server_port = 8443;
        $contract->protocol = 'vless';
        $contract->transport = 'tcp';
        $contract->flow = 'xtls-rprx-vision';
        $contract->sni = 'www.icloud.com';
        $contract->reality_public_key_fingerprint = hash('sha256', $publicKey);
        $contract->short_id = 'ab12cd34';
        $contract->auth_namespace = 'user-uuid';
        return $contract;
    }

    public function test_builds_reality_outbound_for_candidate_ip(): void
    {
        $pub = 'NiaYMCJi0BmseArZXhHUNo8jp0HX4bmE7KtNYcMwy2w';
        $probe = new SingBoxProbe('/nonexistent/sing-box');
        $outbound = $probe->buildOutbound(
            $this->realityContract($pub),
            $this->realityServer($pub),
            '11111111-2222-3333-4444-555555555555',
            '203.0.113.7'
        );

        $this->assertSame('vless', $outbound['type']);
        $this->assertSame('203.0.113.7', $outbound['server']);
        $this->assertSame(8443, $outbound['server_port'], 'probe must target the origin port, not the client-facing relay port');
        $this->assertSame('xtls-rprx-vision', $outbound['flow']);
        $this->assertSame('www.icloud.com', $outbound['tls']['server_name']);
        $this->assertSame($pub, $outbound['tls']['reality']['public_key']);
        $this->assertSame('ab12cd34', $outbound['tls']['reality']['short_id']);
    }

    public function test_live_key_mismatch_against_fingerprint_throws(): void
    {
        $probe = new SingBoxProbe('/nonexistent/sing-box');
        $this->expectException(SuzuEndpointException::class);
        $probe->buildOutbound(
            $this->realityContract('AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'),
            $this->realityServer('NiaYMCJi0BmseArZXhHUNo8jp0HX4bmE7KtNYcMwy2w'),
            '11111111-2222-3333-4444-555555555555',
            '203.0.113.7'
        );
    }

    public function test_builds_trojan_grpc_outbound(): void
    {
        $server = new Server();
        $server->id = 23;
        $server->type = Server::TYPE_TROJAN;
        $server->host = 'b1.example.test';
        $server->port = 8443;
        $server->server_port = 8443;
        $server->protocol_settings = [
            'tls' => 1, 'network' => 'grpc',
            'tls_settings' => ['server_name' => 'b1.example.test', 'allow_insecure' => false],
            'network_settings' => ['serviceName' => 'SuzuStream'],
        ];
        $contract = new SuzuEndpointContract();
        $contract->logical_node_id = 23;
        $contract->legacy_hostname = 'b1.example.test';
        $contract->client_port = 8443;
        $contract->server_port = 8443;
        $contract->protocol = 'trojan';
        $contract->transport = 'grpc';
        $contract->sni = 'b1.example.test';
        $contract->grpc_service_name = 'SuzuStream';
        $contract->auth_namespace = 'user-uuid';

        $probe = new SingBoxProbe('/nonexistent/sing-box');
        $outbound = $probe->buildOutbound($contract, $server, 'uuid-1', '203.0.113.9');

        $this->assertSame('trojan', $outbound['type']);
        $this->assertSame('uuid-1', $outbound['password']);
        $this->assertSame('grpc', $outbound['transport']['type']);
        $this->assertSame('SuzuStream', $outbound['transport']['service_name']);
        $this->assertSame(['h2'], $outbound['tls']['alpn']);
    }
}
