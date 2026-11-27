<?php

namespace Tests\Feature\Batch3;

use App\Models\Plan;
use App\Models\Setting as SettingModel;
use App\Models\User;
use App\Support\Setting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TrafficBackfillTest extends TestCase
{
    use RefreshDatabase;

    private function member(?int $method, ?int $due): User
    {
        $plan = Plan::create(['name' => 'Test plan', 'reset_traffic_method' => $method]);
        return User::withoutEvents(fn () => User::create([
            'email' => Str::uuid() . '@example.test',
            'password' => 'unused-fixture-password',
            'uuid' => Str::uuid()->toString(),
            'token' => Str::random(32),
            'plan_id' => $plan->id,
            'expired_at' => time() + 86400 * 45,
            'next_reset_at' => $due,
            'u' => 23,
            'd' => 45,
        ]));
    }

    public function test_force_clears_obsolete_due_time_when_system_reset_is_disabled(): void
    {
        $user = $this->member(Plan::RESET_TRAFFIC_FOLLOW_SYSTEM, time() - 60);
        $this->disableSystemReset();

        $this->assertSame(0, Artisan::call('reset:traffic', ['--force' => true]));
        $this->assertNull($user->fresh()->next_reset_at);
    }

    public function test_scheduled_reset_clears_obsolete_due_time_and_preserves_usage(): void
    {
        $user = $this->member(Plan::RESET_TRAFFIC_FOLLOW_SYSTEM, time() - 60);
        $this->disableSystemReset();

        $this->assertSame(0, Artisan::call('reset:traffic'));
        $this->assertDatabaseHas('v2_user', ['id' => $user->id, 'next_reset_at' => null, 'u' => 23, 'd' => 45]);
        $this->assertSame(0, $user->trafficResetLogs()->count());
    }

    public function test_null_backfill_is_idempotent_and_skips_users_without_reset_policy(): void
    {
        $monthly = $this->member(Plan::RESET_TRAFFIC_MONTHLY, null);
        $never = $this->member(Plan::RESET_TRAFFIC_NEVER, null);
        $lifetime = $this->member(Plan::RESET_TRAFFIC_MONTHLY, null);
        $lifetime->updateQuietly(['expired_at' => null]);

        $this->assertSame(0, Artisan::call('reset:traffic', ['--fix-null' => true]));
        $filled = $monthly->fresh()->next_reset_at;
        $this->assertGreaterThan(time(), $filled);
        $this->assertNull($never->fresh()->next_reset_at);
        $this->assertSame(
            Carbon::now(config('app.timezone'))->startOfMonth()->addMonth()->timestamp,
            $lifetime->fresh()->next_reset_at,
        );

        $this->assertSame(0, Artisan::call('reset:traffic', ['--fix-null' => true]));
        $this->assertSame($filled, $monthly->fresh()->next_reset_at);
    }

    public function test_null_backfill_and_migration_fail_when_an_eligible_row_is_still_null(): void
    {
        $user = $this->member(Plan::RESET_TRAFFIC_MONTHLY, null);
        DB::unprepared('CREATE TEMP TRIGGER skip_backfill BEFORE UPDATE OF next_reset_at ON v2_user
            WHEN OLD.id = ' . (int) $user->id . ' BEGIN SELECT RAISE(IGNORE); END');
        try {
            $this->assertSame(1, Artisan::call('reset:traffic', ['--fix-null' => true]));
            $this->assertNull($user->fresh()->next_reset_at);
            $migration = require database_path('migrations/2026_04_19_000001_backfill_users_next_reset_at.php');
            $this->expectExceptionMessage('User reset time backfill failed');
            $migration->up();
        } finally {
            DB::unprepared('DROP TRIGGER skip_backfill');
        }
    }

    private function disableSystemReset(): void
    {
        config(['cache.stores.redis' => ['driver' => 'array']]);
        SettingModel::createOrUpdate('reset_traffic_method', (string) Plan::RESET_TRAFFIC_NEVER);
        app()->forgetInstance(Setting::class);
    }
}
