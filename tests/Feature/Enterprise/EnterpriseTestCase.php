<?php

namespace Tests\Feature\Enterprise;

use App\Models\EnterpriseInventoryKit;
use App\Models\EnterpriseInventoryNode;
use App\Models\EnterpriseInventoryRule;
use App\Models\EnterprisePurpose;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\Enterprise\EnterpriseDnsService;
use App\Services\Enterprise\EnterpriseKitBuilderService;
use App\Services\Enterprise\KitProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shared fixtures for the SB-ENT-INV-01 acceptance suite.
 *
 * Group layout mirrors production: 1 = ordinary, 2 = VIP/SVIP (the
 * enterprise_vip_group_id default), 5 = enterprise template group.
 * The network edge is faked: KitProbe always succeeds (unless $probeHealthy
 * is flipped) and reports the rule's target IP as the exit IP; DNS alias
 * creation always succeeds.
 */
abstract class EnterpriseTestCase extends TestCase
{
    use RefreshDatabase;

    protected bool $probeHealthy = true;
    protected ?object $probe = null;

    protected ServerGroup $ordinaryGroup;
    protected ServerGroup $vipGroup;
    protected ServerGroup $templateGroup;
    protected Plan $enterprisePlan;
    protected EnterprisePurpose $purpose;
    protected ServerMachine $machine;
    protected int $rulePortSeq = 31000;
    protected int $landingPortSeq = 21000;

    protected function setUp(): void
    {
        parent::setUp();

        $this->probe = new class extends \stdClass {
            public bool $healthy = true;
        };
        $healthy = $this->probe;
        $fakeProbe = new class($healthy) implements KitProbe {
            public function __construct(private object $state)
            {
            }
            public function tcpReachable(string $host, int $port): bool
            {
                return $this->state->healthy;
            }
            public function probeThroughNode(EnterpriseInventoryKit $kit, int $serverId, User $probeUser): array
            {
                if (!$this->state->healthy) {
                    return ['ok' => false, 'exit_ip' => null, 'status' => 0, 'error' => 'fake probe unhealthy'];
                }
                $node = EnterpriseInventoryNode::where('kit_id', $kit->id)->where('server_id', $serverId)->first();
                $rule = $node ? EnterpriseInventoryRule::where('kit_id', $kit->id)->where('node_role', $node->node_role)->first() : null;
                return ['ok' => true, 'exit_ip' => $rule?->target_ip, 'status' => 204, 'error' => null];
            }
        };
        $this->app->instance(KitProbe::class, $fakeProbe);
        $this->app->instance(EnterpriseDnsService::class, new class implements EnterpriseDnsService {
            public function ensureAlias(string $alias, string $targetHost): bool
            {
                return true;
            }
        });

        // Groups 1..5 in production order.
        $this->ordinaryGroup = $this->makeGroup('普通组');            // 1
        $this->vipGroup = $this->makeGroup('VIP组');                  // 2
        $this->makeGroup('预留组3');                                   // 3
        $this->makeGroup('预留组4');                                   // 4
        $this->templateGroup = $this->makeGroup('企业模板组');         // 5

        $this->machine = ServerMachine::create([
            'name' => 'JP-LANDING-1',
            'token' => Str::random(40),
            'is_active' => true,
        ]);

        $this->enterprisePlan = Plan::create([
            'group_id' => $this->templateGroup->id,
            'transfer_enable' => 500,
            'name' => '业务-企业专用套餐',
            'speed_limit' => null,
            'show' => 1,
            'sort' => 0,
            'renew' => 1,
            'prices' => [Plan::PERIOD_MONTHLY => 99],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'capacity_limit' => null,
            'sell' => 1,
            'device_limit' => 2,
            'enterprise_purpose_required' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->purpose = EnterprisePurpose::where('code', 'JP_TIKTOK')->firstOrFail();
        $this->purpose->machine_id = $this->machine->id;
        $this->purpose->entry_provider = EnterprisePurpose::PROVIDER_CSMZZMB;
        $this->purpose->entry_host = 'aws353.xdfzfzf.xin';
        $this->purpose->landing_port_base = 21000;
        $this->purpose->entry_port_base = 31000;
        $this->purpose->dns_alias_suffix = 'ent.suzu.life';
        $this->purpose->save();
    }

    protected function makeGroup(string $name): ServerGroup
    {
        $group = new ServerGroup();
        $group->name = $name;
        $group->save();
        return $group;
    }

    protected function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => 'ent-' . Str::random(8) . '@example.com',
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

    /** A VIP-group server (the shared premium lines every enterprise kit joins). */
    protected function makeVipServer(array $overrides = []): Server
    {
        return Server::create(array_merge([
            'name' => 'VIP-日本1',
            'type' => Server::TYPE_VLESS,
            'host' => 'vip1.example.test',
            'port' => '443',
            'server_port' => 443,
            'group_ids' => [(string) $this->vipGroup->id],
            'show' => true,
            'enabled' => true,
            'rate' => 1,
            'sort' => 401,
            'protocol_settings' => $this->realitySettings(),
        ], $overrides));
    }

    protected function makePlainServer(array $groupIds, array $overrides = []): Server
    {
        return Server::create(array_merge([
            'name' => '普通节点',
            'type' => Server::TYPE_VLESS,
            'host' => 'plain.example.test',
            'port' => '443',
            'server_port' => 443,
            'group_ids' => array_map('strval', $groupIds),
            'show' => true,
            'enabled' => true,
            'rate' => 1,
            'sort' => 100,
            'protocol_settings' => $this->realitySettings(),
        ], $overrides));
    }

    protected function realitySettings(): array
    {
        return [
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
                'allow_insecure' => false,
            ],
            'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
            'multiplex' => ['enabled' => false],
            'encryption' => null,
        ];
    }

    /** Register one READY primary/backup rule pair landing on the same machine IP. */
    protected function registerRulePair(?EnterprisePurpose $purpose = null, string $targetIp = '203.0.113.10'): array
    {
        $purpose ??= $this->purpose;
        $pair = [];
        foreach ([EnterpriseInventoryNode::ROLE_PRIMARY, EnterpriseInventoryNode::ROLE_BACKUP] as $i => $role) {
            $pair[] = EnterpriseInventoryRule::create([
                'purpose_id' => $purpose->id,
                'provider' => $purpose->entry_provider,
                'public_entry_port' => ++$this->rulePortSeq,
                'target_ip' => $targetIp,
                'target_port' => ++$this->landingPortSeq,
                'provider_rule_ref' => 'TEST-RULE-' . $this->rulePortSeq,
                'status' => EnterpriseInventoryRule::STATUS_READY,
            ]);
        }
        return $pair;
    }

    /** Build one kit through the real builder; asserts it lands READY. */
    protected function buildReadyKit(?EnterprisePurpose $purpose = null): EnterpriseInventoryKit
    {
        $purpose ??= $this->purpose;
        $this->registerRulePair($purpose);
        $kit = app(EnterpriseKitBuilderService::class)->buildKit($purpose);
        $this->assertSame(
            EnterpriseInventoryKit::STATUS_READY,
            $kit->status,
            'fixture kit should verify READY with the healthy fake probe'
        );
        return $kit;
    }

    /** Subscription node ids visible to a user, via the real render path. */
    protected function subscriptionServerIds(User $user): array
    {
        $servers = \App\Services\ServerService::getAvailableServers($user->fresh());
        return collect($servers)->map(fn($s) => (int) (is_array($s) ? $s['id'] : $s->id))->all();
    }
}
