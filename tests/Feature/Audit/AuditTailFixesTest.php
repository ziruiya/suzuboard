<?php

namespace Tests\Feature\Audit;

use App\Http\Middleware\RequestLog;
use App\Models\AdminAuditLog;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\SubscribeTemplate;
use App\Models\User;
use App\Protocols\QuantumultX;
use App\Protocols\Surfboard;
use App\Protocols\Surge;
use App\Services\NodeSyncService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
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
        Redis::shouldReceive('publish')->never();

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
        Redis::shouldReceive('publish')
            ->once()
            ->with('node:push', Mockery::on(function ($payload) use (&$published) {
                $published[] = json_decode($payload, true);
                return true;
            }));
        Redis::shouldReceive('srem')
            ->once()
            ->with(NodeSyncService::USERS_RESYNC_PENDING_KEY, $server->id);

        $this->artisan('suzu:node-resync')->assertExitCode(0);

        $this->assertCount(1, $published);
        $this->assertSame('sync.users', $published[0]['event']);
        $ids = collect($published[0]['data']['users'])->pluck('id')->all();
        $this->assertContains($live->id, $ids);
        $this->assertNotContains($banned->id, $ids);
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
}
