<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed the global admin setting `subscribe_update_interval_hours`
     * with its spec default (6) when absent (SB-SUB-INTERVAL v1.0).
     * The value is a plain integer string in v2_settings; it can later be
     * changed from the admin subscribe settings page.
     */
    public function up(): void
    {
        $exists = DB::table('v2_settings')->where('name', 'subscribe_update_interval_hours')->exists();
        if (!$exists) {
            DB::table('v2_settings')->insert([
                'name' => 'subscribe_update_interval_hours',
                'value' => '6',
            ]);
        }

        // Settings are cached forever in Redis (App\Support\Setting);
        // drop the cache so the seeded value is visible immediately.
        try {
            Cache::store('redis')->forget('admin_settings');
        } catch (\Throwable) {
            // Cache backend unavailable during migration: reads fall
            // back to the same default of 6, so this is safe.
        }
    }

    public function down(): void
    {
        DB::table('v2_settings')->where('name', 'subscribe_update_interval_hours')->delete();

        try {
            Cache::store('redis')->forget('admin_settings');
        } catch (\Throwable) {
        }
    }
};
