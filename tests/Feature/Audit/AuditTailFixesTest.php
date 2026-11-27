<?php

namespace Tests\Feature\Audit;

use App\Http\Controllers\V1\Client\ClientController;
use App\Http\Middleware\RequestLog;
use App\Models\AdminAuditLog;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\SubscribeTemplate;
use App\Models\User;
use App\Protocols\Loon;
use App\Protocols\QuantumultX;
use App\Protocols\Shadowrocket;
use App\Protocols\Surfboard;
use App\Protocols\Surge;
use App\Services\NodeSyncService;
use App\Support\AbstractProtocol;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Enterprise\EnterpriseTestCase;

/**
 * SB-AUD tail batch: AUD-024 / AUD-035 / AUD-037 / AUD-040 / AUD-032 / AUD-033.
 */
class AuditTailFixesTest extends EnterpriseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        // Same environmental workaround as AgentNodeVisibilityTest: the
        // Setting singleton and SubscribeTemplate hardcode the redis
        // cache store, which does not exist in the test environment.
        config()->set('cache.stores.redis', ['driver' => 'array']);
        Cache::forgetDriver('redis');
        app()->forgetInstance(\App\Support\Setting::class);
    }

    // -----------------------------------------------------------------
    // AUD-024: audit log must redact nested secrets, keep debug fields
    // -----------------------------------------------------------------
    public function test_audit_log_redacts_nested_secrets_but_keeps_plain_fields(): void
    {
        $admin = $this->makeUser(['is_admin' => 1]);

        $request = Request::create(
            '/api/v2/secure/server/manage/save?token=topsecret&foo=bar',
            'POST',
            [
                'id' => 5,
                'name' => 'node-x',
                'host' => '203.0.113.7',
                'password' => 'plainpw',
                'cert_config' => [
                    'domain' => 'x.example.test',
                    'private_key' => 'PRIVATE-KEY-DATA',
                    'cert' => 'CERT-DATA',
                ],
                'protocol_settings' => [
                    'reality_settings' => [
                        'private_key' => 'REALITY-PRIV',
                        'public_key' => 'REALITY-PUB',
                    ],
                ],
                'auth_data' => ['uuid' => 'user-uuid-1'],
                'subscribe_url' => 'https://example.test/s/usertoken',
            ]
        );
        $request->setUserResolver(fn() => $admin);

        $response = (new RequestLog())->handle($request, fn() => new Response('ok'));
        $this->assertSame('ok', $response->getContent());

        $row = AdminAuditLog::query()->orderByDesc('id')->firstOrFail();
        $data = json_decode($row->request_data, true);

        // Plain troubleshooting fields survive untouched.
        $this->assertSame(5, $data['id']);
        $this->assertSame('node-x', $data['name']);
        $this->assertSame('203.0.113.7', $data['host']);
        $this->assertSame('x.example.test', $data['cert_config']['domain']);

        // Secrets are masked at any depth.
        $this->assertSame('***', $data['password']);
        $this->assertSame('***', $data['cert_config']['private_key']);
        $this->assertSame('***', $data['cert_config']['cert']);
        $this->assertSame('***', $data['protocol_settings']['reality_settings']['private_key']);
        $this->assertSame('***', $data['protocol_settings']['reality_settings']['public_key']);
        $this->assertSame('***', $data['auth_data']['uuid']);
        $this->assertSame('***', $data['subscribe_url']);
        $this->assertStringNotContainsString('PRIVATE-KEY-DATA', $row->request_data);
        $this->assertStringNotContainsString('usertoken', $row->request_data);

        // URI query: sensitive params masked, the rest kept.
        $this->assertStringContainsString('token=%2A%2A%2A', $row->uri);
        $this->assertStringContainsString('foo=bar', $row->uri);
        $this->assertStringContainsString('/server/manage/save', $row->uri);
    }

    // -----------------------------------------------------------------
    // AUD-037: PlanObserver must react to reset_traffic_method changes
    // -----------------------------------------------------------------
    public function test_plan_observer_recomputes_next_reset_when_method_changes(): void
    {
        $plan = Plan::create([
            'name' => 'AUD037 plan',
            'group_id' => $this->ordinaryGroup->id,
            'transfer_enable' => 100,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_NEVER,
            'content' => '',
        ]);
        $user = $this->makeUser([
            'plan_id' => $plan->id,
            'group_id' => $this->ordinaryGroup->id,
            'expired_at' => time() + 86400 * 90,
        ]);
        // Plant the sentinel directly: user save hooks maintain
        // next_reset_at themselves and would overwrite it on create.
        DB::table('v2_user')->where('id', $user->id)->update(['next_reset_at' => 12345]);

        // Changing an unrelated field must not touch next_reset_at.
        $plan->update(['name' => 'AUD037 plan renamed']);
        $this->assertSame(12345, (int) $user->fresh()->next_reset_at);

        // Changing the reset method must recompute it (wasChanged path);
        // with isDirty() in the updated hook this never happened.
        $plan->update(['reset_traffic_method' => Plan::RESET_TRAFFIC_FIRST_DAY_MONTH]);
        $next = (int) $user->fresh()->next_reset_at;
        $this->assertNotSame(12345, $next);
        $expected = now()->startOfMonth()->addMonth()->timestamp;
        $this->assertSame($expected, $next);
    }

    // -----------------------------------------------------------------
    // AUD-040: callback_no unique, NULLs repeatable
    // -----------------------------------------------------------------
    public function test_callback_no_unique_index_and_duplicate_rejected(): void
    {
        $names = collect(Schema::getIndexes('v2_order'))->pluck('name');
        $this->assertContains('v2_order_callback_no_unique', $names);

        $insert = function (?string $callbackNo) {
            DB::table('v2_order')->insert([
                'user_id' => 1,
                'plan_id' => 1,
                'type' => 1,
                'period' => 'month_price',
                'trade_no' => Str::random(24),
                'callback_no' => $callbackNo,
                'total_amount' => 100,
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        };

        $insert(null);
        $insert(null);
        $insert('CB-UNIQUE-1');
        $this->expectException(\Illuminate\Database\QueryException::class);
        $insert('CB-UNIQUE-1');
    }

    // -----------------------------------------------------------------
    // AUD-032: QuantumultX cannot express Trojan+gRPC
    // -----------------------------------------------------------------
    public function test_quantumultx_omits_trojan_grpc_but_keeps_other_transports(): void
    {
        $grpc = $this->trojanServer(['network' => 'grpc', 'network_settings' => ['serviceName' => 'SuzuStream']]);
        $this->assertSame('', QuantumultX::buildTrojan('pw', $grpc));

        $ws = $this->trojanServer(['network' => 'ws', 'network_settings' => ['path' => '/SuzuWebSocket']]);
        $line = QuantumultX::buildTrojan('pw', $ws);
        $this->assertStringContainsString('obfs=wss', $line);
        $this->assertStringContainsString('tag=C-Test', $line);

        $tcp = $this->trojanServer(['network' => 'tcp']);
        $line = QuantumultX::buildTrojan('pw', $tcp);
        $this->assertStringContainsString('trojan=node.example.test:8443', $line);
        $this->assertStringContainsString('over-tls=true', $line);
    }

    // -----------------------------------------------------------------
    // AUD-033: Surge / Surfboard cannot express Trojan+gRPC
    // -----------------------------------------------------------------
    public function test_surge_and_surfboard_omit_trojan_grpc_but_keep_plain_trojan(): void
    {
        SubscribeTemplate::updateOrCreate(['name' => 'surge'], ['content' => "[Proxy]\n\$proxies\n[Proxy Group]\nG = select, \$proxy_group\n"]);
        SubscribeTemplate::updateOrCreate(['name' => 'surfboard'], ['content' => "[Proxy]\n\$proxies\n[Proxy Group]\nG = select, \$proxy_group\n"]);
        Cache::store('redis')->flush();

        $user = ['u' => 0, 'd' => 0, 'transfer_enable' => 107374182400, 'expired_at' => null, 'token' => 'tok123'];
        $servers = [
            array_merge($this->trojanServer(['network' => 'grpc', 'network_settings' => ['serviceName' => 'SuzuStream']]), ['name' => 'C-GRPC']),
            array_merge($this->trojanServer(['network' => 'tcp']), ['name' => 'C-PLAIN']),
        ];

        $surge = (new Surge($user, $servers))->handle()->getContent();
        $this->assertStringContainsString('C-PLAIN', $surge);
        $this->assertStringNotContainsString('C-GRPC', $surge);

        $surfboard = (new Surfboard($user, $servers))->handle()->getContent();
        $this->assertStringContainsString('C-PLAIN', $surfboard);
        $this->assertStringNotContainsString('C-GRPC', $surfboard);
    }

    // -----------------------------------------------------------------
    // AUD-035: missed deltas are marked and reconciled from the DB
    // -----------------------------------------------------------------
    public function test_notify_user_removed_marks_node_for_resync_even_when_offline(): void
    {
        $server = $this->makePlainServer([(string) $this->ordinaryGroup->id]);

        Redis::shouldReceive('sadd')
            ->once()
            ->with(NodeSyncService::USERS_RESYNC_PENDING_KEY, $server->id);
        Redis::shouldReceive('eval')->never();

        // Node is offline (no node_ws_alive cache): the instant push is
        // skipped, but the resync mark must still be recorded.
        NodeSyncService::notifyUserRemovedFromGroup(999001, $this->ordinaryGroup->id);
    }

    public function test_node_resync_repushes_full_user_list_from_db_and_clears_mark(): void
    {
        $server = $this->makePlainServer([(string) $this->ordinaryGroup->id]);
        $live = $this->makeUser([
            'group_id' => $this->ordinaryGroup->id,
            'transfer_enable' => 100000000,
            'expired_at' => time() + 86400,
        ]);
        $banned = $this->makeUser([
            'group_id' => $this->ordinaryGroup->id,
            'transfer_enable' => 100000000,
            'expired_at' => time() + 86400,
            'banned' => 1,
        ]);
        Cache::put("node_ws_alive:{$server->id}", true);

        $published = [];
        Redis::shouldReceive('smembers')
            ->once()
            ->with(NodeSyncService::USERS_RESYNC_PENDING_KEY)
            ->andReturn([$server->id]);
        Redis::shouldReceive('eval')
            ->once()
            ->withArgs(function ($script, $keys, $key, $channel, $header, $data, $delta) use (&$published, $server) {
                $published[] = json_decode($header . substr($data, 1) . '}', true, flags: JSON_THROW_ON_ERROR);
                return $keys === 1
                    && $key === "node:state_revision:{$server->id}:users"
                    && $channel === config('database.redis.options.prefix') . 'node:push'
                    && $delta === '0';
            })
            ->andReturn(1);
        Redis::shouldReceive('srem')
            ->once()
            ->with(NodeSyncService::USERS_RESYNC_PENDING_KEY, $server->id);

        Redis::shouldReceive('hgetall')
            ->once()
            ->with(NodeSyncService::CONFIG_RESYNC_PENDING_KEY)
            ->andReturn([]);

        $this->artisan('suzu:node-resync')->assertExitCode(0);

        $this->assertCount(1, $published);
        $this->assertSame('sync.users', $published[0]['event']);
        $ids = collect($published[0]['data']['users'])->pluck('id')->all();
        $this->assertContains($live->id, $ids);
        $this->assertNotContains($banned->id, $ids);
    }

    public function test_shadowrocket_applies_version_floor_and_version_independent_whitelist(): void
    {
        $anytls = array_replace($this->trojanServer([]), [
            'name' => 'New-AnyTLS',
            'type' => Server::TYPE_ANYTLS,
            'protocol_settings' => [],
        ]);
        $unknownTransport = array_replace($this->trojanServer(['network' => 'xhttp']), ['name' => 'Bad-Trojan']);
        $plain = array_replace($this->trojanServer(['network' => 'tcp']), ['name' => 'Plain-Trojan']);
        $servers = [$anytls, $unknownTransport, $plain];

        foreach (['2591', '2592', null, 'invalid'] as $version) {
            $content = base64_decode((new Shadowrocket($this->subscriptionUser(), $servers, 'shadowrocket', $version))
                ->handle()->getContent(), true);
            $this->assertStringContainsString('Plain-Trojan', $content);
            $this->assertStringNotContainsString('Bad-Trojan', $content);
            $this->assertSame($version === '2592', str_contains($content, 'New-AnyTLS'));
        }
    }

    public function test_shadowrocket_applies_transport_whitelist_with_no_version(): void
    {
        $server = array_replace($this->trojanServer(['network' => 'xhttp']), ['name' => 'Bad-Trojan']);
        $request = Request::create('/subscribe', 'GET', ['flag' => 'shadowrocket']);
        $content = app(ClientController::class)->doSubscribe($request, $this->subscriptionUser(), [$server])->getContent();

        $this->assertStringNotContainsString('Bad-Trojan', base64_decode($content, true));
    }

    public function test_incompatible_rule_applies_without_a_client_version(): void
    {
        $servers = [
            $this->trojanServer(['network' => 'tcp']),
            $this->trojanServer(['network' => 'xhttp']),
        ];
        $renderer = new class([], $servers) extends AbstractProtocol {
            protected $protocolRequirements = [
                '*.trojan.incompatible' => ['protocol_settings.network' => ['xhttp']],
            ];

            public function handle(): array
            {
                return $this->servers;
            }
        };

        $this->assertSame([$servers[0]], $renderer->handle());
    }

    public function test_transport_matrix_only_emits_supported_combinations(): void
    {
        $ws = ['network' => 'ws', 'network_settings' => ['path' => '/ws-test']];
        $vmessWs = $this->trojanServer($ws);
        $vmessGrpc = $this->trojanServer(['network' => 'grpc']);
        $vmessReality = $this->trojanServer(['network' => 'tcp', 'tls' => 2]);

        foreach ([QuantumultX::class, Surge::class, Surfboard::class] as $client) {
            $this->assertStringContainsString('/ws-test', $client::buildVmess('pw', $vmessWs));
            $this->assertSame('', $client::buildVmess('pw', $vmessGrpc));
            $this->assertSame('', $client::buildVmess('pw', $vmessReality));
        }
        $this->assertSame('', QuantumultX::buildVless('pw', $vmessGrpc));
        $this->assertSame('', Surge::buildTrojan('pw', $this->trojanServer($ws)));
        $this->assertSame('', Surfboard::buildTrojan('pw', $this->trojanServer($ws)));
        $trojanDefaultTls = $this->trojanServer([]);
        unset($trojanDefaultTls['protocol_settings']['tls']);
        $this->assertStringContainsString('over-tls=true', QuantumultX::buildTrojan('pw', $trojanDefaultTls));
    }

    public function test_hysteria_obfuscation_includes_type_and_password_when_supported(): void
    {
        $server = $this->hysteriaServer(2, 'Obfuscated');
        $server['protocol_settings']['obfs'] = ['open' => true, 'type' => 'salamander', 'password' => 'obfs-test'];
        $this->assertStringContainsString('salamander-password=obfs-test', Loon::buildHysteria('pw', $server, []));
        $this->assertSame('', Surge::buildHysteria('pw', $server));

        $server['protocol_settings']['obfs']['type'] = 'unsupported-type';
        $this->assertSame('', Loon::buildHysteria('pw', $server, []));
        $this->assertSame('', Surge::buildHysteria('pw', $server));
        $content = base64_decode((new Shadowrocket($this->subscriptionUser(), [$server]))->handle()->getContent(), true);
        $this->assertStringNotContainsString('Obfuscated', $content);
    }

    public function test_unknown_requested_types_yield_empty_subscription(): void
    {
        $controller = app(ClientController::class);
        $server = $this->trojanServer(['network' => 'tcp']);

        foreach (['unknown-type', '|,｜'] as $types) {
            $request = Request::create('/subscribe', 'GET', ['types' => $types]);
            $this->assertSame('', $controller->doSubscribe($request, $this->subscriptionUser(), [$server])->getContent());
        }

        $request = Request::create('/subscribe');
        $content = $controller->doSubscribe($request, $this->subscriptionUser(), [$server])->getContent();
        $this->assertStringContainsString('trojan://', base64_decode($content, true));
    }

    public function test_surge_groups_only_include_rendered_hysteria_version(): void
    {
        SubscribeTemplate::updateOrCreate(['name' => 'surge'], ['content' => "[Proxy]\n\$proxies\n[Proxy Group]\nG = select, \$proxy_group\n"]);
        Cache::store('redis')->flush();

        $servers = [
            $this->hysteriaServer(1, 'Old-Hysteria'),
            $this->hysteriaServer(2, 'New-Hysteria'),
            $this->hysteriaServer(3, 'Future-Hysteria'),
        ];
        $content = (new Surge($this->subscriptionUser(), $servers, 'surge', '2398'))->handle()->getContent();
        $this->assertStringContainsString('New-Hysteria = hysteria2', $content);
        $this->assertStringContainsString('G = select, New-Hysteria', $content);
        $this->assertStringNotContainsString('Old-Hysteria', $content);
        $this->assertStringNotContainsString('Future-Hysteria', $content);
    }

    public function test_surge_omits_salamander_regardless_of_version_metadata(): void
    {
        SubscribeTemplate::updateOrCreate(['name' => 'surge'], ['content' => "[Proxy]\n\$proxies\n[Proxy Group]\nG = select, \$proxy_group\n"]);
        Cache::store('redis')->flush();

        $plain = $this->hysteriaServer(2, 'Plain-Hysteria');
        $obfuscated = $this->hysteriaServer(2, 'Salamander-Hysteria');
        $obfuscated['protocol_settings']['obfs'] = ['open' => true, 'type' => 'salamander', 'password' => 'obfs-test'];

        foreach ([[null, null], ['surge', null], ['surge', '1'], ['surge', '2398'], ['surge', '9999']] as [$client, $version]) {
            $content = (new Surge($this->subscriptionUser(), [$plain, $obfuscated], $client, $version))->handle()->getContent();
            $this->assertStringNotContainsString('Salamander-Hysteria', $content);
            $this->assertStringNotContainsString('salamander-password', $content);
            if ($client === null || in_array($version, ['2398', '9999'], true)) {
                $this->assertStringContainsString('Plain-Hysteria = hysteria2', $content);
                $this->assertStringContainsString('G = select, Plain-Hysteria', $content);
            } else {
                $this->assertStringNotContainsString('Plain-Hysteria', $content);
            }
        }
    }

    private function subscriptionUser(): array
    {
        return ['u' => 0, 'd' => 0, 'transfer_enable' => 107374182400, 'expired_at' => null, 'token' => 'test-token'];
    }

    private function hysteriaServer(int $version, string $name): array
    {
        return array_replace($this->trojanServer([]), [
            'type' => Server::TYPE_HYSTERIA,
            'name' => $name,
            'protocol_settings' => ['version' => $version, 'tls' => ['server_name' => 'node.example.test']],
        ]);
    }

    private function trojanServer(array $protocolOverrides): array
    {
        return [
            'type' => Server::TYPE_TROJAN,
            'host' => 'node.example.test',
            'port' => 8443,
            'name' => 'C-Test',
            'password' => 'pw',
            'protocol_settings' => array_merge([
                'tls' => 1,
                'tls_settings' => ['server_name' => 's.example.test', 'allow_insecure' => false],
            ], $protocolOverrides),
        ];
    }

    public function test_admin_audit_masks_webhook_urls_in_configuration(): void
    {
        $admin = $this->makeUser(['is_admin' => true]);
        $request = Request::create('/api/v2/secret/config/save', 'POST', [
            'telegram_webhook_url' => 'https://hooks.example.test/hook?access_token=TEST-CREDENTIAL',
            'telegram_bot_enable' => true,
            'server_ws_url' => 'wss://example.test/stream?options%5Bapi_key%5D=OTHER-TEST-CREDENTIAL',
            'app_url' => 'https://admin:TEST-PASSWORD@example.test/',
            'tos_url' => 'https://example.test/tos?lang=zh',
        ]);
        $request->setUserResolver(fn () => $admin);
        (new RequestLog())->handle($request, fn () => new Response('ok'));

        $data = json_decode(AdminAuditLog::latest('id')->firstOrFail()->request_data, true);
        $this->assertSame('***', $data['telegram_webhook_url']);
        $this->assertTrue($data['telegram_bot_enable']);
        $this->assertSame('***', $data['server_ws_url']);
        $this->assertSame('***', $data['app_url']);
        $this->assertSame('https://example.test/tos?lang=zh', $data['tos_url']);
    }

}
