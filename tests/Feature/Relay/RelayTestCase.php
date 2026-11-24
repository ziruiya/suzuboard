<?php

namespace Tests\Feature\Relay;

use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\Relay\RelayMachineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shared fixtures for the SB-RELAY-01 panel-side suite.
 */
abstract class RelayTestCase extends TestCase
{
    use RefreshDatabase;

    protected ServerGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        $group = new ServerGroup();
        $group->name = '普通组';
        $group->save();
        $this->group = $group;
    }

    protected function makeMachine(string $name, string $tier = 'small'): ServerMachine
    {
        return ServerMachine::create([
            'name' => $name,
            'token' => Str::random(40),
            'is_active' => true,
            'relay_tier' => $tier,
        ]);
    }

    protected function enableRelay(ServerMachine $machine): ServerMachine
    {
        return app(RelayMachineService::class)->enable($machine);
    }

    protected function makeNode(ServerMachine $machine, array $overrides = []): Server
    {
        return Server::create(array_merge([
            'name' => '节点-' . Str::random(4),
            'type' => Server::TYPE_VLESS,
            'host' => 'node-' . Str::random(6) . '.example.test',
            'port' => '443',
            'server_port' => 443,
            'group_ids' => [(string) $this->group->id],
            'show' => true,
            'enabled' => true,
            'machine_id' => $machine->id,
            'rate' => 1,
            'sort' => 100,
            'protocol_settings' => [
                'tls' => 2,
                'flow' => 'xtls-rprx-vision',
                'network' => 'tcp',
                'network_settings' => null,
                'tls_settings' => ['server_name' => null, 'allow_insecure' => false],
                'reality_settings' => [
                    'server_name' => 'www.icloud.com',
                    'server_port' => '443',
                    'public_key' => 'NiaYMCJi0BmseArZXhHUNo8jp0HX4bmE7KtNYcMwy2w',
                    'private_key' => 'DUMMY-PRIVATE-KEY-FOR-TESTS-ONLY-0000000000',
                    'short_id' => 'ab12cd34',
                ],
            ],
        ], $overrides));
    }

    protected function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => 'relay-' . Str::random(8) . '@example.com',
            'password' => bcrypt('password'),
            'uuid' => Str::uuid()->toString(),
            'token' => Str::random(32),
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

    /** A MANUAL rule payload on a public bind, allowlist approved. */
    protected function manualRule(array $overrides = []): array
    {
        return array_merge([
            'name' => '手动线',
            'transport' => 'tcp',
            'listen_ip' => '0.0.0.0',
            'listen_port' => 20001,
            'target_type' => 'MANUAL',
            'target_host' => '203.0.113.10',
            'target_port' => 8443,
            'allowlist_cidrs' => ['0.0.0.0/0'],
            'allowlist_approved' => true,
        ], $overrides);
    }
}
