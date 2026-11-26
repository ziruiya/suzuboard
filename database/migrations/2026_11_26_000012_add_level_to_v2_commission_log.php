<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SB-MONEY-005 (index hardening, deferred from fix batch 1): the
 * payout logic was fixed in batch 1 (state-transition guard, row lock
 * + status re-verification inside the payout transaction), but the
 * books themselves carried no uniqueness guarantee — a payout that
 * somehow ran twice for the same order and level would write a second
 * CommissionLog row and a second balance increment with nothing at
 * the schema level to stop it.
 *
 * Design: one row per (order, distribution level). payHandle() walks
 * levels 0..2 and each level has a distinct beneficiary, so
 * UNIQUE(trade_no, level) expresses exactly the payout semantics and
 * still permits the legitimate three-row multi-level payout.
 *
 * Backfill: existing rows are levelled by id order within each
 * trade_no (the order payHandle inserted them). The precheck refuses
 * to run — loudly — if any order has more rows than the level space
 * (3): that shape cannot be levelled safely and needs a human audit
 * first. (Production was audited at batch-1 deploy time: 2 rows
 * total, one per order, zero duplicates.)
 *
 * MySQL 5.7 self-check: one small column + one composite unique index
 * on existing short columns (trade_no varchar + tinyint) — no 5.7
 * concerns. Idempotent guards mirror the batch-2/3 migrations.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('v2_commission_log')
            || Schema::hasColumn('v2_commission_log', 'level')) {
            return;
        }

        $overcrowded = DB::table('v2_commission_log')
            ->select('trade_no')
            ->groupBy('trade_no')
            ->havingRaw('COUNT(*) > 3')
            ->pluck('trade_no');
        if ($overcrowded->isNotEmpty()) {
            throw new \RuntimeException(
                'SB-MONEY-005 index hardening aborted: commission log rows per order exceed the 3-level space for trade_no(s): '
                . $overcrowded->implode(', ')
                . ' — audit historical duplicates before adding the unique key.'
            );
        }

        Schema::table('v2_commission_log', function (Blueprint $table) {
            $table->unsignedTinyInteger('level')->nullable();
        });

        $rows = DB::table('v2_commission_log')
            ->orderBy('trade_no')
            ->orderBy('id')
            ->get(['id', 'trade_no']);
        $levelByTradeNo = [];
        foreach ($rows as $row) {
            $level = $levelByTradeNo[$row->trade_no] ?? 0;
            DB::table('v2_commission_log')
                ->where('id', $row->id)
                ->update(['level' => $level]);
            $levelByTradeNo[$row->trade_no] = $level + 1;
        }

        Schema::table('v2_commission_log', function (Blueprint $table) {
            $table->unique(['trade_no', 'level'], 'v2_commission_log_trade_level_unique');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_commission_log')
            || !Schema::hasColumn('v2_commission_log', 'level')) {
            return;
        }
        Schema::table('v2_commission_log', function (Blueprint $table) {
            $table->dropUnique('v2_commission_log_trade_level_unique');
            $table->dropColumn('level');
        });
    }
};
