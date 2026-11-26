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
 * Backfill: rows without a level are levelled by id order within
 * each trade_no (the order payHandle inserted them), skipping levels
 * already recorded for that order. The precheck refuses to run —
 * loudly — if any order has more rows than the level space (3): that
 * shape cannot be levelled safely and needs a human audit first.
 * (Production was audited at batch-1 deploy time: 2 rows total, one
 * per order, zero duplicates.)
 *
 * M-P2-02: the migration is staged and re-entrant. An earlier run may
 * have died between adding the column, backfilling it and adding the
 * unique index; each stage is detected on its own (column presence,
 * recorded-level legality, index presence) and only the missing
 * stages run. Recorded level values are the book of record and are
 * NEVER overwritten — if they are contradictory (out of range or
 * duplicated within an order) the remaining rows cannot be levelled
 * safely and the migration fails loudly instead of guessing.
 *
 * MySQL 5.7 self-check: one small column + one composite unique index
 * on existing short columns (trade_no varchar + tinyint) — no 5.7
 * concerns. Idempotent guards mirror the batch-2/3 migrations.
 */
return new class extends Migration {
    private const INDEX_NAME = 'v2_commission_log_trade_level_unique';

    public function up(): void
    {
        if (!Schema::hasTable('v2_commission_log')) {
            return;
        }

        // Stage 1: the level column.
        if (!Schema::hasColumn('v2_commission_log', 'level')) {
            Schema::table('v2_commission_log', function (Blueprint $table) {
                $table->unsignedTinyInteger('level')->nullable();
            });
        }

        // Loud precheck (kept): an order with more rows than the
        // 3-level space cannot be levelled safely.
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

        // Stage 2: backfill the rows that still have no level.
        $this->backfillLevels();

        // Stage 3: the unique index.
        if (!$this->hasTradeLevelUnique()) {
            Schema::table('v2_commission_log', function (Blueprint $table) {
                $table->unique(['trade_no', 'level'], self::INDEX_NAME);
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_commission_log')
            || !Schema::hasColumn('v2_commission_log', 'level')) {
            return;
        }
        if ($this->hasTradeLevelUnique()) {
            Schema::table('v2_commission_log', function (Blueprint $table) {
                $table->dropUnique(self::INDEX_NAME);
            });
        }
        Schema::table('v2_commission_log', function (Blueprint $table) {
            $table->dropColumn('level');
        });
    }

    /**
     * Fill in levels for rows that do not have one yet, in id order
     * per trade_no, taking the lowest level not already recorded for
     * that order. Recorded levels are validated first: an out-of-range
     * or duplicated recorded level means the books cannot be levelled
     * safely, so the migration aborts loudly rather than overwriting
     * or guessing.
     */
    private function backfillLevels(): void
    {
        $rows = DB::table('v2_commission_log')
            ->orderBy('trade_no')
            ->orderBy('id')
            ->get(['id', 'trade_no', 'level']);

        $takenByTradeNo = [];
        foreach ($rows as $row) {
            if ($row->level === null) {
                continue;
            }
            $level = (int) $row->level;
            if ($level < 0 || $level > 2 || isset($takenByTradeNo[$row->trade_no][$level])) {
                throw new \RuntimeException(
                    'SB-MONEY-005 index hardening aborted: recorded commission level for trade_no '
                    . $row->trade_no . ' is out of range or duplicated'
                    . ' — audit the recorded levels before backfilling.'
                );
            }
            $takenByTradeNo[$row->trade_no][$level] = true;
        }

        foreach ($rows as $row) {
            if ($row->level !== null) {
                continue;
            }
            $taken = $takenByTradeNo[$row->trade_no] ?? [];
            $level = null;
            foreach ([0, 1, 2] as $candidate) {
                if (!isset($taken[$candidate])) {
                    $level = $candidate;
                    break;
                }
            }
            if ($level === null) {
                throw new \RuntimeException(
                    'SB-MONEY-005 index hardening aborted: no free level remains for trade_no '
                    . $row->trade_no . ' — audit historical duplicates before adding the unique key.'
                );
            }
            DB::table('v2_commission_log')
                ->where('id', $row->id)
                ->update(['level' => $level]);
            $takenByTradeNo[$row->trade_no][$level] = true;
        }
    }

    private function hasTradeLevelUnique(): bool
    {
        return collect(Schema::getIndexes('v2_commission_log'))
            ->contains(fn($index) => ($index['name'] ?? null) === self::INDEX_NAME);
    }
};
