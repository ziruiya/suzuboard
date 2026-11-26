<?php

namespace Tests\Feature\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class BatchFourRollbackTest extends TestCase
{
    use RefreshDatabase;

    private function migration(string $name): Migration
    {
        return require database_path("migrations/$name.php");
    }

    public function test_model_a_sku_rollback_refuses_inventory_and_order_references(): void
    {
        $migration = $this->migration('2026_11_24_000003_enterprise_model_a_sku_catalog');
        $purposes = DB::table('suzu_enterprise_purpose');
        $sku = (clone $purposes)->where('code', 'JP_TRADE')->sole();
        $legacy = (clone $purposes)->where('code', 'JP_TIKTOK')->sole();

        $kit = DB::table('suzu_enterprise_inventory_kits')->insertGetId([
            'purpose_id' => $sku->id, 'stock_group_id' => 50,
        ]);
        $this->assertRefused(fn () => $migration->down(), 'suzu_enterprise_inventory_kits');
        $this->assertTrue((clone $purposes)->where('id', $sku->id)->exists());
        $this->assertTrue(DB::table('suzu_enterprise_inventory_config')->where('purpose_id', $sku->id)->exists());
        DB::table('suzu_enterprise_inventory_kits')->where('id', $kit)->delete();

        $order = DB::table('v2_order')->insertGetId([
            'user_id' => 1, 'plan_id' => 1, 'type' => 1, 'period' => 'month_price',
            'trade_no' => 'model-a-rollback-test', 'total_amount' => 100,
            'created_at' => 1, 'updated_at' => 1, 'enterprise_purpose_code' => 'JP_TRADE',
        ]);
        $this->assertRefused(fn () => $migration->down(), 'v2_order');
        $this->assertTrue(Schema::hasColumn('suzu_enterprise_purpose', 'region'));
        DB::table('v2_order')->where('id', $order)->delete();

        $migration->down();
        $this->assertFalse((clone $purposes)->where('code', 'JP_TRADE')->exists());
        $migration->up();
        $this->assertTrue((clone $purposes)->where('code', 'JP_TRADE')->exists());
        $this->assertSame($legacy->id, (clone $purposes)->where('code', 'JP_TIKTOK')->value('id'));
    }

    public function test_unified_server_rollback_round_trips_reality_and_trojan_grpc(): void
    {
        $migration = $this->migration('2025_01_05_131425_create_v2_server_table');
        $reality = [
            'server_name' => 'reality.example.test', 'server_port' => 443,
            'public_key' => 'test-public', 'private_key' => 'test-private', 'short_id' => 'test-short',
        ];
        $parent = $this->server('vless', '450', ['tls' => 2, 'network' => 'tcp', 'reality_settings' => $reality]);
        $child = $this->server('vless', null, ['tls' => 2, 'network' => 'tcp', 'reality_settings' => $reality], $parent);
        $this->server('trojan', '510', [
            'network' => 'grpc', 'network_settings' => ['serviceName' => 'trojan-fixture'],
            'tls_settings' => ['server_name' => 'tls.example.test', 'allow_insecure' => true],
        ]);

        $migration->down();
        $this->assertFalse(Schema::hasTable('v2_server'));
        $this->assertSame(450, DB::table('v2_server_vless')->where('id', $child)->value('parent_id'));
        $this->assertEquals($reality, json_decode(DB::table('v2_server_vless')->where('id', 450)->value('tls_settings'), true));
        $legacyTrojan = DB::table('v2_server_trojan')->where('id', 510)->sole();
        $this->assertSame('tls.example.test', $legacyTrojan->server_name);
        $this->assertSame(['serviceName' => 'trojan-fixture'], json_decode($legacyTrojan->networkSettings, true));

        $migration->up();
        $roundTrip = DB::table('v2_server')->where('type', 'trojan')->where('code', '510')->sole();
        $this->assertSame('trojan-fixture', json_decode($roundTrip->protocol_settings, true)['network_settings']['serviceName']);
        $this->assertSame(2, DB::table('v2_server')->where('type', 'vless')->count());
    }

    public function test_unified_server_rollback_preflights_id_collisions_without_creating_tables(): void
    {
        $migration = $this->migration('2025_01_05_131425_create_v2_server_table');
        $settings = ['tls' => 0, 'network' => 'tcp'];
        $id = $this->server('vmess', null, $settings);
        $collision = $this->server('vmess', (string) $id, $settings);

        $this->assertRefused(fn () => $migration->down(), 'conflicting legacy ID');
        $this->assertFalse(Schema::hasTable('v2_server_trojan'));
        $this->assertFalse(Schema::hasTable('v2_server_vmess'));
        $this->assertSame(2, DB::table('v2_server')->count());
        DB::table('v2_server')->where('id', $collision)->delete();

        $migration->down();
        $this->assertTrue(DB::table('v2_server_vmess')->where('id', $id)->exists());
        $migration->up();
        $this->assertSame((string) $id, DB::table('v2_server')->sole()->code);
    }

    private function server(string $type, ?string $code, array $settings, ?int $parentId = null): int
    {
        return DB::table('v2_server')->insertGetId([
            'type' => $type, 'code' => $code, 'parent_id' => $parentId, 'group_ids' => '[1]',
            'name' => 'Fixture', 'rate' => 1, 'host' => 'node.example.test',
            'port' => '8443', 'server_port' => 8443, 'protocol_settings' => json_encode($settings),
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    public function test_stat_index_rollback_preflights_both_tables_and_can_retry(): void
    {
        $migration = $this->migration('2026_11_25_000003_fix_stat_unique_keys_record_type');
        $stats = DB::table('v2_stat_server');
        $row = [
            'server_id' => 1, 'server_type' => 'vless', 'u' => 10, 'd' => 20,
            'record_at' => 1767225600, 'created_at' => 1, 'updated_at' => 1,
        ];
        $stats->insert([$row + ['record_type' => 'd'], $row + ['record_type' => 'm']]);

        $this->assertRefused(fn () => $migration->down(), 'v2_stat_server');
        $this->assertTrue(Schema::hasIndex('v2_stat_user', 'stat_user_rate_user_at_type_uq', 'unique'));
        $this->assertTrue(Schema::hasIndex('v2_stat_server', 'stat_server_id_type_at_type_uq', 'unique'));
        $stats->where('record_type', 'm')->delete();

        $migration->down();
        $this->assertTrue(Schema::hasIndex('v2_stat_user', 'server_rate_user_id_record_at', 'unique'));
        $this->assertTrue(Schema::hasIndex('v2_stat_server', 'server_id_server_type_record_at', 'unique'));
        $migration->up();
        DB::table('v2_stat_server')->insert($row + ['record_type' => 'm']);
        $this->assertSame(2, DB::table('v2_stat_server')->count());
    }

    private function assertRefused(callable $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail('Rollback should have been refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($reason, $e->getMessage());
        }
    }
}
