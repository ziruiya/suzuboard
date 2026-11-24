<?php

use App\Models\EnterpriseInventoryConfig;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SB-ENT-INV-01 batch 2 — model A (D06): region-first SKU catalog.
 *
 * The catalog grows from the 4 batch-1 purposes to 8 SKUs
 * (2 regions x 4 usages). The four batch-1 codes — JP_TIKTOK, JP_SHOPPING,
 * HK_TRADE, HK_GENERAL — are also model-A codes (identity legacy mapping,
 * see EnterpriseSkuCatalog::LEGACY_CODE_MAP): their rows are kept as-is
 * apart from the region/usage decomposition and display order, so existing
 * orders, intents and kits keep resolving to the same SKU. The four new
 * SKUs (JP_TRADE, JP_GENERAL, HK_TIKTOK, HK_SHOPPING) are inserted with
 * their inventory config at the D03 target of 3 READY kits each.
 *
 * The SKU table below is a frozen snapshot of
 * App\Services\Enterprise\EnterpriseSkuCatalog at this migration's date;
 * the catalog class remains the runtime source of truth.
 */
return new class extends Migration {
    /** @var array<int, array{code: string, region: string, usage: string, name: string, protocol: string, sort: int}> */
    private array $skus = [
        ['code' => 'JP_TIKTOK', 'region' => 'JP', 'usage' => 'TIKTOK', 'name' => '日本-TikTok用', 'protocol' => 'vless_reality', 'sort' => 1],
        ['code' => 'JP_SHOPPING', 'region' => 'JP', 'usage' => 'SHOPPING', 'name' => '日本-购物拍卖', 'protocol' => 'vless_reality', 'sort' => 2],
        ['code' => 'JP_TRADE', 'region' => 'JP', 'usage' => 'TRADE', 'name' => '日本-外贸', 'protocol' => 'vless_tls_ws', 'sort' => 3],
        ['code' => 'JP_GENERAL', 'region' => 'JP', 'usage' => 'GENERAL', 'name' => '日本-通用', 'protocol' => 'vless_reality', 'sort' => 4],
        ['code' => 'HK_TIKTOK', 'region' => 'HK', 'usage' => 'TIKTOK', 'name' => '香港-TikTok用', 'protocol' => 'vless_reality', 'sort' => 5],
        ['code' => 'HK_SHOPPING', 'region' => 'HK', 'usage' => 'SHOPPING', 'name' => '香港-购物拍卖', 'protocol' => 'vless_reality', 'sort' => 6],
        ['code' => 'HK_TRADE', 'region' => 'HK', 'usage' => 'TRADE', 'name' => '香港-外贸', 'protocol' => 'vless_tls_ws', 'sort' => 7],
        ['code' => 'HK_GENERAL', 'region' => 'HK', 'usage' => 'GENERAL', 'name' => '香港-通用', 'protocol' => 'vless_reality', 'sort' => 8],
    ];

    /** Codes that did not exist before model A (removed again by down()). */
    private array $newCodes = ['JP_TRADE', 'JP_GENERAL', 'HK_TIKTOK', 'HK_SHOPPING'];

    public function up(): void
    {
        Schema::table('suzu_enterprise_purpose', function (Blueprint $table) {
            $table->string('region', 8)->nullable();       // JP | HK (model A decomposition)
            $table->string('usage_key', 16)->nullable();   // TIKTOK | SHOPPING | TRADE | GENERAL
        });

        $now = date('Y-m-d H:i:s');
        foreach ($this->skus as $sku) {
            $existing = DB::table('suzu_enterprise_purpose')->where('code', $sku['code'])->first();
            if ($existing) {
                // Legacy row: keep ops-owned blueprint fields and protocol
                // untouched; only backfill decomposition + display order.
                DB::table('suzu_enterprise_purpose')->where('id', $existing->id)->update([
                    'region' => $sku['region'],
                    'usage_key' => $sku['usage'],
                    'sort' => $sku['sort'],
                    'updated_at' => $now,
                ]);
                $purposeId = $existing->id;
            } else {
                $purposeId = DB::table('suzu_enterprise_purpose')->insertGetId([
                    'code' => $sku['code'],
                    'name' => $sku['name'],
                    'sort' => $sku['sort'],
                    'is_active' => 1,
                    'protocol' => $sku['protocol'],
                    'region' => $sku['region'],
                    'usage_key' => $sku['usage'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            // Every SKU carries an inventory config at the D03 target (3).
            $configExists = DB::table('suzu_enterprise_inventory_config')
                ->where('purpose_id', $purposeId)
                ->exists();
            if (!$configExists) {
                DB::table('suzu_enterprise_inventory_config')->insert([
                    'purpose_id' => $purposeId,
                    'target_level' => EnterpriseInventoryConfig::DEFAULT_TARGET_LEVEL,
                    'current_level' => 0,
                    'lead_days' => 3,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Any other historical row (none expected) gets its decomposition
        // parsed from the "<REGION>_<USAGE>" code shape.
        $rows = DB::table('suzu_enterprise_purpose')->whereNull('region')->get();
        foreach ($rows as $row) {
            $parts = explode('_', (string) $row->code, 2);
            if (count($parts) === 2) {
                DB::table('suzu_enterprise_purpose')->where('id', $row->id)->update([
                    'region' => $parts[0],
                    'usage_key' => $parts[1],
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('suzu_enterprise_purpose')->whereIn('code', $this->newCodes)->pluck('id');
        DB::table('suzu_enterprise_inventory_config')->whereIn('purpose_id', $ids)->delete();
        DB::table('suzu_enterprise_purpose')->whereIn('id', $ids)->delete();

        Schema::table('suzu_enterprise_purpose', function (Blueprint $table) {
            $table->dropColumn(['region', 'usage_key']);
        });
    }
};
