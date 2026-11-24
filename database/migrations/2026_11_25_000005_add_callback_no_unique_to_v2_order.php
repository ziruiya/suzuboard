<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SB-AUD-040: v2_order.callback_no carried no unique constraint, so the
 * same payment callback number could be recorded on multiple orders.
 * Production was checked before this migration (2026-11-25): 286
 * non-empty callback_no values, zero duplicates, zero empty strings —
 * adding the unique key cannot conflict with existing data. The
 * migration still refuses to run (loudly) if duplicates ever appear,
 * instead of failing halfway or silently dropping rows. NULLs stay
 * allowed and repeatable (orders that never got a callback), matching
 * MySQL/SQLite unique-index semantics.
 */
return new class extends Migration {
    public function up(): void
    {
        // Normalize empty strings to NULL so they cannot collide.
        DB::table('v2_order')->where('callback_no', '')->update(['callback_no' => null]);

        $duplicates = DB::table('v2_order')
            ->whereNotNull('callback_no')
            ->groupBy('callback_no')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('callback_no');
        if ($duplicates->isNotEmpty()) {
            throw new \RuntimeException(
                'AUD-040: duplicate v2_order.callback_no values exist (' .
                $duplicates->take(10)->implode(', ') .
                '); dedupe manually before adding the unique index.'
            );
        }

        if (!$this->hasIndex('v2_order', 'v2_order_callback_no_unique')) {
            Schema::table('v2_order', function (Blueprint $table) {
                $table->unique('callback_no', 'v2_order_callback_no_unique');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('v2_order', 'v2_order_callback_no_unique')) {
            Schema::table('v2_order', function (Blueprint $table) {
                $table->dropUnique('v2_order_callback_no_unique');
            });
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        try {
            foreach (Schema::getIndexes($table) as $index) {
                if (($index['name'] ?? null) === $name) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }
        return false;
    }
};
