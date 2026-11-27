<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * One-shot backfill of users.next_reset_at for legacy installs.
 *
 * Replaces the previous `reset:traffic --force` step in `xboard:update`,
 * which had to run on every container start. Now it runs exactly once per
 * database (Laravel migrations are tracked).
 */
return new class extends Migration {
    public function up(): void
    {
        if (Artisan::call('reset:traffic', ['--fix-null' => true]) !== 0) {
            throw new RuntimeException('User reset time backfill failed: ' . Artisan::output());
        }
    }

    public function down(): void
    {
        // Backfill is non-destructive; nothing to roll back.
    }
};
