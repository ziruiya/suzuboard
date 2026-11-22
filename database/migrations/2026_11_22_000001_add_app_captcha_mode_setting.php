<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed the runtime setting app_captcha_mode with its default
     * ('turnstile' = current enforced behaviour) when absent.
     * The value is a plain string in v2_settings; the deploy flips it
     * to 'exempt' through the admin_setting write path.
     */
    public function up(): void
    {
        $exists = DB::table('v2_settings')->where('name', 'app_captcha_mode')->exists();
        if (!$exists) {
            DB::table('v2_settings')->insert([
                'name' => 'app_captcha_mode',
                'value' => 'turnstile',
            ]);
        }

        // Settings are cached forever in Redis (App\Support\Setting);
        // drop the cache so the seeded value is visible immediately.
        try {
            Cache::store('redis')->forget('admin_settings');
        } catch (\Throwable) {
            // Cache backend unavailable during migration: reads fall
            // back to the same 'turnstile' default, so this is safe.
        }
    }

    public function down(): void
    {
        DB::table('v2_settings')->where('name', 'app_captcha_mode')->delete();

        try {
            Cache::store('redis')->forget('admin_settings');
        } catch (\Throwable) {
        }
    }
};
