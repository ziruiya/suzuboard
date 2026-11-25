<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SB-ER-007: separate "last probe attempt" from "last probe PASS".
 *
 * EnterpriseInventoryHealthService::recordResult() used to stamp
 * last_probe_at on every outcome, so a kit whose probes kept failing
 * still looked freshly probed and stayed assignment-eligible. The
 * assignment freshness gate now reads last_probe_ok_at (only ever
 * stamped by a passing probe); last_probe_at remains the last attempt
 * for scheduling/display.
 *
 * Backfill: kits with a clean failure counter take their current
 * last_probe_at as the best available approximation of the last pass;
 * kits with recorded failures start unstamped (they re-qualify on
 * their next passing probe, which the patrol runs continuously).
 *
 * MySQL 5.7 self-check: single nullable DATETIME column, no index —
 * no 5.7 concerns.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('suzu_enterprise_inventory_kits')
            && !Schema::hasColumn('suzu_enterprise_inventory_kits', 'last_probe_ok_at')) {
            Schema::table('suzu_enterprise_inventory_kits', function (Blueprint $table) {
                $table->dateTime('last_probe_ok_at')->nullable();
            });
            DB::table('suzu_enterprise_inventory_kits')
                ->where('health_fail_count', 0)
                ->whereNotNull('last_probe_at')
                ->update(['last_probe_ok_at' => DB::raw('last_probe_at')]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('suzu_enterprise_inventory_kits')
            && Schema::hasColumn('suzu_enterprise_inventory_kits', 'last_probe_ok_at')) {
            Schema::table('suzu_enterprise_inventory_kits', function (Blueprint $table) {
                $table->dropColumn('last_probe_ok_at');
            });
        }
    }
};
