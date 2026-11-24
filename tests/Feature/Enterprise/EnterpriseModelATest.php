<?php

namespace Tests\Feature\Enterprise;

use App\Models\EnterpriseInventoryAlert;
use App\Models\EnterpriseInventoryConfig;
use App\Models\EnterpriseInventoryKit;
use App\Models\EnterpriseInventoryRule;
use App\Models\EnterpriseOrderIntent;
use App\Models\EnterprisePurpose;
use App\Models\Order;
use App\Models\Plan;
use App\Services\Enterprise\EnterpriseCapacityService;
use App\Services\Enterprise\EnterpriseInventoryService;
use App\Services\Enterprise\EnterpriseLifecycleService;
use App\Services\Enterprise\EnterpriseSkuCatalog;
use App\Services\OrderService;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

/**
 * SB-ENT-INV-01 batch 2 — model A (D06):
 *  - region-first 8-SKU catalog (2 regions x 4 usages), one SKU per order;
 *  - batch-1 legacy purpose codes keep resolving to the same SKU, and a
 *    legacy customer's renewal keeps finding the original kit;
 *  - two-level (region -> usage) purchase selection submits a single SKU;
 *  - one customer never holds more than one active assignment;
 *  - per-SKU READY target is a config item (default 3);
 *  - csmzzmb rule-capacity guardrails: warn at 80%, stop at 90%.
 */
class EnterpriseModelATest extends EnterpriseTestCase
{
    private function uriBySuffix(string $suffix): string
    {
        foreach (Route::getRoutes() as $route) {
            if (str_ends_with($route->uri(), $suffix)) {
                return '/' . $route->uri();
            }
        }
        $this->fail("route *{$suffix} is not registered");
    }

    private function purchaseAndPay($user, ?string $purposeCode): Order
    {
        $order = OrderService::createFromRequest(
            $user,
            $this->enterprisePlan,
            Plan::PERIOD_MONTHLY,
            null,
            $purposeCode
        );
        (new OrderService($order))->paid('CB-' . $order->trade_no);
        return $order->fresh();
    }

    /** Fill a purpose's blueprint the same way TestCase does for JP_TIKTOK. */
    private function configurePurpose(EnterprisePurpose $purpose): EnterprisePurpose
    {
        $purpose->machine_id = $this->machine->id;
        $purpose->entry_provider = EnterprisePurpose::PROVIDER_CSMZZMB;
        $purpose->entry_host = 'aws353.xdfzfzf.xin';
        $purpose->landing_port_base = 21000;
        $purpose->entry_port_base = 31000;
        $purpose->dns_alias_suffix = 'ent.suzu.life';
        $purpose->save();
        return $purpose->fresh();
    }

    private function purposeByCode(string $code): EnterprisePurpose
    {
        return $this->configurePurpose(EnterprisePurpose::where('code', $code)->firstOrFail());
    }

    public function test_catalog_is_region_first_with_eight_skus(): void
    {
        $this->assertSame(
            ['JP_TIKTOK', 'JP_SHOPPING', 'JP_TRADE', 'JP_GENERAL', 'HK_TIKTOK', 'HK_SHOPPING', 'HK_TRADE', 'HK_GENERAL'],
            EnterpriseSkuCatalog::codes()
        );

        $levels = EnterpriseSkuCatalog::twoLevel();
        $this->assertCount(2, $levels);
        $this->assertSame('JP', $levels[0]['code']);
        $this->assertSame('HK', $levels[1]['code']);
        foreach ($levels as $level) {
            $this->assertCount(4, $level['usages'], 'each region offers exactly 4 usages');
        }

        $this->assertSame('HK_TIKTOK', EnterpriseSkuCatalog::compose('hk', 'tiktok'));
        $this->assertSame('JP_TRADE', EnterpriseSkuCatalog::compose('JP', 'TRADE'));
        $this->assertNull(EnterpriseSkuCatalog::compose('JP', 'NOPE'));
        $this->assertNull(EnterpriseSkuCatalog::compose('XX', 'TIKTOK'));
        $this->assertNull(EnterpriseSkuCatalog::compose('JP', null));
        $this->assertSame(['region' => 'HK', 'usage' => 'GENERAL'], EnterpriseSkuCatalog::decompose('HK_GENERAL'));

        // Blueprint protocol presumption: trade = SW1, everything else Reality.
        $byCode = collect(EnterpriseSkuCatalog::skus())->keyBy('code');
        $this->assertSame(EnterprisePurpose::PROTOCOL_SW1, $byCode['JP_TRADE']['protocol']);
        $this->assertSame(EnterprisePurpose::PROTOCOL_SW1, $byCode['HK_TRADE']['protocol']);
        $this->assertSame(EnterprisePurpose::PROTOCOL_REALITY, $byCode['JP_TIKTOK']['protocol']);
        $this->assertSame(EnterprisePurpose::PROTOCOL_REALITY, $byCode['HK_SHOPPING']['protocol']);
    }

    public function test_all_eight_purposes_seeded_with_config_target_three(): void
    {
        $this->assertSame(8, EnterprisePurpose::count());
        foreach (EnterpriseSkuCatalog::codes() as $code) {
            $purpose = EnterprisePurpose::where('code', $code)->first();
            $this->assertNotNull($purpose, $code);
            $this->assertNotNull($purpose->config, $code);
            $this->assertSame(3, (int) $purpose->config->target_level, $code);
            $parts = EnterpriseSkuCatalog::decompose($code);
            $this->assertSame($parts['region'], $purpose->regionCode(), $code);
            $this->assertSame($parts['usage'], $purpose->usageKey(), $code);
        }

        // The READY target is a config item, not a hard-coded constant:
        // changing the row changes what the inventory service aims for.
        $service = app(EnterpriseInventoryService::class);
        $hkShopping = EnterprisePurpose::where('code', 'HK_SHOPPING')->firstOrFail();
        $this->assertSame(3, $service->targetLevel($hkShopping->id));
        EnterpriseInventoryConfig::where('purpose_id', $hkShopping->id)->update(['target_level' => 5]);
        $this->assertSame(5, $service->targetLevel($hkShopping->id));
    }

    public function test_legacy_codes_normalize_to_the_same_model_a_sku(): void
    {
        foreach (EnterpriseSkuCatalog::LEGACY_CODE_MAP as $legacy => $sku) {
            $this->assertSame($sku, EnterpriseSkuCatalog::normalizeCode($legacy));
            $this->assertSame($sku, EnterpriseSkuCatalog::normalizeCode(strtolower($legacy)));
            $this->assertSame($sku, EnterpriseSkuCatalog::normalizeCode(' ' . $legacy . ' '));
            $this->assertNotNull(
                EnterprisePurpose::where('code', $sku)->where('is_active', true)->first(),
                "legacy code {$legacy} must stay resolvable"
            );
        }
        $this->assertNull(EnterpriseSkuCatalog::normalizeCode('JP_UNKNOWN'));
        $this->assertNull(EnterpriseSkuCatalog::normalizeCode(''));
        $this->assertNull(EnterpriseSkuCatalog::normalizeCode(null));
    }

    public function test_each_of_the_eight_skus_assigns_its_own_kit(): void
    {
        foreach (EnterpriseSkuCatalog::codes() as $code) {
            $purpose = $this->purposeByCode($code);
            $kit = $this->buildReadyKit($purpose);
            $this->registerRulePair($purpose); // stock for the post-assignment replenishment build
            $user = $this->makeUser();

            $order = $this->purchaseAndPay($user, $code);

            $user = $user->fresh();
            $this->assertSame($code, $order->enterprise_purpose_code, $code);
            $this->assertSame($kit->id, (int) $user->enterprise_kit_id, $code);
            $this->assertSame(EnterpriseInventoryKit::STATUS_ASSIGNED, $kit->fresh()->status, $code);

            $intent = EnterpriseOrderIntent::where('order_id', $order->id)->first();
            $this->assertNotNull($intent, $code);
            $this->assertSame($purpose->id, (int) $intent->purpose_id, $code);

            $activeAssignments = EnterpriseInventoryKit::where('current_user_id', $user->id)
                ->whereIn('status', [
                    EnterpriseInventoryKit::STATUS_ASSIGNED,
                    EnterpriseInventoryKit::STATUS_ASSIGNED_LEGACY,
                    EnterpriseInventoryKit::STATUS_GRACE_HELD,
                ])
                ->count();
            $this->assertSame(1, $activeAssignments, $code);
        }
    }

    public function test_two_level_selection_composes_a_single_sku(): void
    {
        $purpose = $this->purposeByCode('HK_TIKTOK');
        $kit = $this->buildReadyKit($purpose);
        $this->registerRulePair($purpose);
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $response = $this->postJson($this->uriBySuffix('user/order/save'), [
            'plan_id' => $this->enterprisePlan->id,
            'period' => 'month_price',
            'enterprise_region' => 'HK',
            'enterprise_usage' => 'TIKTOK',
        ]);
        $response->assertOk();

        $order = Order::where('user_id', $user->id)->latest('id')->first();
        $this->assertNotNull($order);
        $this->assertSame('HK_TIKTOK', $order->enterprise_purpose_code, 'region+usage must compose exactly one SKU');
        $intents = EnterpriseOrderIntent::where('order_id', $order->id)->get();
        $this->assertCount(1, $intents);
        $this->assertSame($purpose->id, (int) $intents->first()->purpose_id);

        (new OrderService($order))->paid('CB-2LEVEL');
        $this->assertSame($kit->id, (int) $user->fresh()->enterprise_kit_id);
    }

    public function test_two_level_selection_with_unknown_region_creates_no_order(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->postJson($this->uriBySuffix('user/order/save'), [
            'plan_id' => $this->enterprisePlan->id,
            'period' => 'month_price',
            'enterprise_region' => 'XX',
            'enterprise_usage' => 'TIKTOK',
        ]);

        $this->assertSame(0, Order::where('user_id', $user->id)->count());
        $this->assertSame(0, EnterpriseOrderIntent::where('user_id', $user->id)->count());
    }

    public function test_legacy_customer_renewal_keeps_original_kit(): void
    {
        // HK_TRADE is a batch-1 legacy code; its entitlement must survive
        // the model-A switch untouched.
        $purpose = $this->purposeByCode('HK_TRADE');
        $kit = $this->buildReadyKit($purpose);
        $this->registerRulePair($purpose);
        $this->registerRulePair($purpose);
        $user = $this->makeUser();
        $this->purchaseAndPay($user, 'HK_TRADE');
        $this->assertSame($kit->id, (int) $user->fresh()->enterprise_kit_id);

        $user->expired_at = time() - 3600;
        $user->save();
        app(EnterpriseLifecycleService::class)->expireAssignedKits();
        $this->assertSame(EnterpriseInventoryKit::STATUS_GRACE_HELD, $kit->fresh()->status);

        // Renewal submits the legacy code in lower case: normalization maps
        // it back to HK_TRADE and the SAME kit is restored.
        $order2 = $this->purchaseAndPay($user->fresh(), 'hk_trade');

        $this->assertSame('HK_TRADE', $order2->enterprise_purpose_code);
        $this->assertSame($kit->id, (int) $order2->enterprise_kit_id);
        $this->assertSame($kit->id, (int) $user->fresh()->enterprise_kit_id);
        $this->assertSame(EnterpriseInventoryKit::STATUS_ASSIGNED, $kit->fresh()->status);
    }

    public function test_repurchase_never_stacks_a_second_sku_assignment(): void
    {
        $kitA = $this->buildReadyKit(); // JP_TIKTOK, configured by TestCase
        $this->registerRulePair();
        $user = $this->makeUser();
        $this->purchaseAndPay($user, 'JP_TIKTOK');
        $this->assertSame($kitA->id, (int) $user->fresh()->enterprise_kit_id);

        $purposeB = $this->purposeByCode('HK_GENERAL');
        $kitB = $this->buildReadyKit($purposeB);
        $this->registerRulePair($purposeB);

        // Expire into grace, then renew while naming a DIFFERENT SKU.
        // Model A: one active assignment per customer — the original kit
        // is restored and the other SKU's kit is never claimed.
        $user->expired_at = time() - 3600;
        $user->save();
        app(EnterpriseLifecycleService::class)->expireAssignedKits();
        $this->purchaseAndPay($user->fresh(), 'HK_GENERAL');

        $this->assertSame($kitA->id, (int) $user->fresh()->enterprise_kit_id);
        $this->assertSame(EnterpriseInventoryKit::STATUS_ASSIGNED, $kitA->fresh()->status);
        $this->assertSame(EnterpriseInventoryKit::STATUS_READY, $kitB->fresh()->status);
        $active = EnterpriseInventoryKit::where('current_user_id', $user->id)
            ->whereIn('status', [
                EnterpriseInventoryKit::STATUS_ASSIGNED,
                EnterpriseInventoryKit::STATUS_ASSIGNED_LEGACY,
                EnterpriseInventoryKit::STATUS_GRACE_HELD,
            ])
            ->count();
        $this->assertSame(1, $active);
    }

    public function test_capacity_guardrail_warns_then_stops_registration(): void
    {
        config(['suzu_enterprise.rule_capacity_total' => 10]);
        $capacity = app(EnterpriseCapacityService::class);
        $this->assertSame(EnterpriseCapacityService::STATE_OK, $capacity->evaluate()['state']);

        $makeRule = function (int $port, string $provider = EnterprisePurpose::PROVIDER_CSMZZMB) {
            return EnterpriseInventoryRule::create([
                'purpose_id' => $this->purpose->id,
                'provider' => $provider,
                'public_entry_port' => $port,
                'target_ip' => '203.0.113.20',
                'target_port' => 21000,
                'status' => EnterpriseInventoryRule::STATUS_READY,
            ]);
        };
        for ($i = 0; $i < 7; $i++) {
            $makeRule(41000 + $i);
        }
        $this->assertSame(EnterpriseCapacityService::STATE_OK, $capacity->evaluate()['state']); // 70%

        $makeRule(41007); // 80% -> warn
        $snapshot = $capacity->evaluate();
        $this->assertSame(EnterpriseCapacityService::STATE_WARN, $snapshot['state']);
        $this->assertSame(80.0, $snapshot['percent']);
        $this->assertTrue(
            EnterpriseInventoryAlert::where('alert_type', EnterpriseInventoryAlert::TYPE_RULE_CAPACITY_WARN)
                ->where('status', EnterpriseInventoryAlert::STATUS_OPEN)
                ->exists(),
            'warn threshold must raise a rule_capacity_warn alert'
        );
        $capacity->assertCanRegister(); // warn level still registers

        // Registration through the admin API at warn level succeeds and
        // pushes occupancy to the 90% stop threshold.
        $admin = $this->makeUser(['is_admin' => 1, 'email' => 'capacity-admin@example.com']);
        Sanctum::actingAs($admin);
        $created = $this->postJson($this->uriBySuffix('suzu/enterprise/rules'), [
            'purpose_id' => $this->purpose->id,
            'provider' => EnterprisePurpose::PROVIDER_CSMZZMB,
            'public_entry_port' => 41008,
            'target_ip' => '203.0.113.20',
            'target_port' => 21000,
            'confirm' => true,
        ]);
        $created->assertOk();
        $this->assertSame(EnterpriseCapacityService::STATE_STOP, $capacity->evaluate()['state']);
        $this->assertTrue(
            EnterpriseInventoryAlert::where('alert_type', EnterpriseInventoryAlert::TYPE_RULE_CAPACITY_STOP)
                ->where('status', EnterpriseInventoryAlert::STATUS_OPEN)
                ->exists(),
            'stop threshold must raise a rule_capacity_stop alert'
        );

        // At stop, csmzzmb registrations are refused...
        $refused = $this->postJson($this->uriBySuffix('suzu/enterprise/rules'), [
            'purpose_id' => $this->purpose->id,
            'provider' => EnterprisePurpose::PROVIDER_CSMZZMB,
            'public_entry_port' => 41009,
            'target_ip' => '203.0.113.20',
            'target_port' => 21000,
            'confirm' => true,
        ]);
        $refused->assertStatus(422);
        $this->assertSame(9, $capacity->registeredCount());

        // ...but other providers are not governed by the csmzzmb cap.
        $other = $this->postJson($this->uriBySuffix('suzu/enterprise/rules'), [
            'purpose_id' => $this->purpose->id,
            'provider' => EnterprisePurpose::PROVIDER_VX,
            'public_entry_port' => 41010,
            'target_ip' => '203.0.113.20',
            'target_port' => 21000,
            'confirm' => true,
        ]);
        $other->assertOk();
        $this->assertSame(9, $capacity->registeredCount(), 'vx rules must not count toward csmzzmb occupancy');
    }

    public function test_catalog_endpoint_and_lines_expose_region_and_usage(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $catalog = $this->getJson($this->uriBySuffix('user/enterprise/catalog'));
        $catalog->assertOk();
        $regions = $catalog->json('data.regions');
        $this->assertCount(2, $regions);
        $this->assertSame('JP', $regions[0]['code']);
        $this->assertSame('HK', $regions[1]['code']);
        $total = 0;
        foreach ($regions as $region) {
            $total += count($region['usages']);
            foreach ($region['usages'] as $usage) {
                $this->assertArrayHasKey('purpose_id', $usage);
                $this->assertStringStartsWith($region['code'] . '_', $usage['code']);
            }
        }
        $this->assertSame(8, $total, 'the purchase catalog offers exactly the 8 SKUs');

        $kit = $this->buildReadyKit(); // JP_TIKTOK
        $this->registerRulePair();
        $buyer = $this->makeUser();
        $this->purchaseAndPay($buyer, 'JP_TIKTOK');
        Sanctum::actingAs($buyer->fresh());
        $lines = $this->getJson($this->uriBySuffix('user/enterprise/lines'));
        $lines->assertOk();
        $data = $lines->json('data');
        $this->assertTrue($data['has_enterprise']);
        $this->assertSame('JP', $data['region_code']);
        $this->assertSame('日本', $data['region_name']);
        $this->assertSame('TIKTOK', $data['usage_code']);
        $this->assertSame('TikTok用', $data['usage_name']);
        $this->assertNotNull($data['primary']);
        $this->assertNotNull($data['backup']);
    }
}
