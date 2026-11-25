<?php

namespace Tests\Feature\Machine;

use App\Models\MachineFulfillment;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\Machine\MachineFulfillmentService;
use App\Services\Machine\MachineNodeProbe;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FakeMachineProbe implements MachineNodeProbe
{
    public bool $reachable = true;

    public function tcpReachable(string $host, int $port): bool
    {
        return $this->reachable;
    }
}

/**
 * Shared fixtures for the business-plans batch 3 suite (dedicated-VPS
 * plan + fulfilment + port rotation).
 */
abstract class MachineTestCase extends TestCase
{
    use RefreshDatabase;

    protected FakeMachineProbe $probe;
    protected ServerGroup $vipGroup;
    protected ServerGroup $plainGroup;
    protected ServerGroup $machineTemplateGroup;
    protected Plan $machinePlan;
    protected Server $vipServer;
    protected ServerMachine $vipMachine;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        // Same Setting/Redis workaround as AgentNodeVisibilityTest: the
        // test environment has no Redis, back the hardcoded store by array.
        config()->set('cache.stores.redis', ['driver' => 'array']);
        \Illuminate\Support\Facades\Cache::forgetDriver('redis');
        app()->forgetInstance(\App\Support\Setting::class);

        $this->probe = new FakeMachineProbe();
        $this->app->instance(MachineNodeProbe::class, $this->probe);

        $this->plainGroup = $this->makeGroup('普通组');
        $this->vipGroup = $this->makeGroup('SVIP组');
        $this->machineTemplateGroup = $this->makeGroup('业务-整机模板');
        admin_setting(['enterprise_vip_group_id' => $this->vipGroup->id]);

        $this->machinePlan = Plan::create([
            'group_id' => $this->machineTemplateGroup->id,
            'transfer_enable' => 1024,
            'name' => '业务套餐-独享整机',
            'speed_limit' => null,
            // Fixture is on-sale so purchase validation passes; the
            // production seed creates the real plan OFF-SALE (show/sell 0).
            'show' => true,
            'sort' => 7,
            'renew' => true,
            'content' => '整机定稿文案',
            'prices' => [Plan::PERIOD_MONTHLY => 299],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
            'capacity_limit' => null,
            'sell' => true,
            'device_limit' => 2,
            'business_tier' => 'machine',
            'machine_region_required' => 1,
        ]);

        $this->vipMachine = ServerMachine::create([
            'name' => 'VIP 共享机',
            'token' => Str::random(40),
            'is_active' => true,
        ]);
        $this->vipServer = Server::create([
            'name' => 'VIP 共享线',
            'type' => Server::TYPE_VLESS,
            'host' => 'vip.example.test',
            'port' => '443',
            'server_port' => 443,
            'group_ids' => [(string) $this->vipGroup->id],
            'show' => true,
            'enabled' => true,
            'machine_id' => $this->vipMachine->id,
            'rate' => 5,
            'sort' => 401,
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
            // SB-ER-010: the production fleet carries the shared DNS-01
            // credential set per-node; this fixture is the test fleet's
            // credentialed reference node, which new TLS nodes clone.
            'cert_config' => [
                'cert_mode' => 'dns',
                'domain' => 'prod.example.test',
                'dns_provider' => 'cloudflare',
                'dns_env' => ['CLOUDFLARE_DNS_API_TOKEN' => 'test-token-not-a-secret'],
                'email' => 'ops@example.com',
            ],
        ]);
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
            'email' => 'buyer-' . Str::random(8) . '@example.com',
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

    protected function buyMachinePlan(User $user, ?string $region = 'JP'): Order
    {
        return OrderService::createFromRequest(
            $user,
            $this->machinePlan,
            Plan::PERIOD_MONTHLY,
            null,
            null,
            $region
        );
    }

    protected function payOrder(Order $order): void
    {
        (new OrderService($order))->paid('CB-' . $order->trade_no);
    }

    protected function service(): MachineFulfillmentService
    {
        return app(MachineFulfillmentService::class);
    }

    /**
     * Full happy path: buy → pay → backfill IP → agent heartbeat → tick.
     * Returns the DELIVERED fulfilment.
     */
    protected function deliverFor(User $user, string $ip = '203.0.113.10'): MachineFulfillment
    {
        $order = $this->buyMachinePlan($user);
        $this->payOrder($order);
        $f = MachineFulfillment::where('order_id', $order->id)->firstOrFail();
        $this->service()->startPurchase($f);
        $this->service()->backfillIp($f->fresh(), [
            'public_ip' => $ip,
            'vendor_name' => 'TestVendor',
            'vendor_instance_id' => 'ins-001',
            'resource_expires_at' => now()->addMonth()->toDateTimeString(),
        ]);
        $machine = ServerMachine::findOrFail($f->fresh()->machine_id);
        $machine->last_seen_at = time();
        $machine->save();
        $this->service()->tick();
        return $f->fresh();
    }
}
