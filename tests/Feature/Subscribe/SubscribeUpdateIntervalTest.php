<?php

namespace Tests\Feature\Subscribe;

use App\Models\AdminAuditLog;
use App\Models\Setting as SettingModel;
use App\Models\User;
use App\Protocols\Clash;
use App\Protocols\ClashMeta;
use App\Protocols\Shadowrocket;
use App\Protocols\SingBox;
use App\Protocols\Stash;
use App\Services\SubscribeUpdateIntervalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SB-SUB-INTERVAL v1.0: the `profile-update-interval` response header
 * (Clash/ClashMeta/SingBox/Stash) is driven by the global admin setting
 * `subscribe_update_interval_hours` (default 6, range 1–72).
 */
class SubscribeUpdateIntervalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SubscribeUpdateIntervalService::resetLastValid();
        try {
            Cache::store('redis')->forget('admin_settings');
            Cache::store('redis')->forget('subscribe_update_interval:last_valid_hours');
        } catch (\Throwable) {
        }
    }

    private function protocolUser(): array
    {
        return [
            'u' => 0,
            'd' => 0,
            'transfer_enable' => 0,
            'expired_at' => null,
            'uuid' => '11111111-2222-3333-4444-555555555555',
            'email' => 'interval@example.test',
        ];
    }

    private function corruptStoredValue(string $raw): void
    {
        SettingModel::createOrUpdate(SubscribeUpdateIntervalService::SETTING_KEY, $raw);
        try {
            Cache::store('redis')->forget('admin_settings');
        } catch (\Throwable) {
        }
        // Simulate the next request: scoped instances (App\Support\Setting
        // with its in-memory snapshot) are rebuilt, so readers actually
        // observe the corrupted stored value.
        $this->app->forgetScopedInstances();
    }

    public function test_default_interval_is_6_when_setting_absent(): void
    {
        // Remove the row seeded by the migration: no valid value anywhere.
        DB::table('v2_settings')
            ->where('name', SubscribeUpdateIntervalService::SETTING_KEY)
            ->delete();
        try {
            Cache::store('redis')->forget('admin_settings');
        } catch (\Throwable) {
        }
        $this->app->forgetScopedInstances();

        $this->assertSame(6, app(SubscribeUpdateIntervalService::class)->hours());

        $response = (new Clash($this->protocolUser(), []))->handle();
        $this->assertSame('6', $response->headers->get('profile-update-interval'));
    }

    public function test_changed_value_takes_effect_on_next_response(): void
    {
        admin_setting([SubscribeUpdateIntervalService::SETTING_KEY => 12]);

        $clash = (new Clash($this->protocolUser(), []))->handle();
        $this->assertSame('12', $clash->headers->get('profile-update-interval'));

        $clashMeta = (new ClashMeta($this->protocolUser(), []))->handle();
        $this->assertSame('12', $clashMeta->headers->get('profile-update-interval'));
    }

    public function test_invalid_stored_value_falls_back_to_last_valid_then_default(): void
    {
        // Prime a valid value: it becomes the last valid value.
        admin_setting([SubscribeUpdateIntervalService::SETTING_KEY => 12]);
        $this->assertSame(12, app(SubscribeUpdateIntervalService::class)->hours());

        // Corrupt the stored value out of band: readers must keep serving
        // the last valid value instead of the garbage.
        $this->corruptStoredValue('999');
        $response = (new Clash($this->protocolUser(), []))->handle();
        $this->assertSame('12', $response->headers->get('profile-update-interval'));

        $this->corruptStoredValue('not-a-number');
        $this->assertSame(12, app(SubscribeUpdateIntervalService::class)->hours());

        // Cold start with no valid value ever seen: spec default 6.
        SubscribeUpdateIntervalService::resetLastValid();
        try {
            Cache::store('redis')->forget('subscribe_update_interval:last_valid_hours');
        } catch (\Throwable) {
        }
        $this->corruptStoredValue('999');
        $this->assertSame(6, app(SubscribeUpdateIntervalService::class)->hours());
    }

    public function test_singbox_interval_comes_from_setting_and_tracks_changes(): void
    {
        // Seeded default (6) is served before any change.
        $response = (new SingBox($this->protocolUser(), []))->handle();
        $this->assertSame('6', $response->headers->get('profile-update-interval'));

        admin_setting([SubscribeUpdateIntervalService::SETTING_KEY => 12]);
        $response = (new SingBox($this->protocolUser(), []))->handle();
        $this->assertSame('12', $response->headers->get('profile-update-interval'));

        // A further change is visible on the very next response.
        admin_setting([SubscribeUpdateIntervalService::SETTING_KEY => 48]);
        $response = (new SingBox($this->protocolUser(), []))->handle();
        $this->assertSame('48', $response->headers->get('profile-update-interval'));
    }

    public function test_stash_interval_comes_from_setting_and_tracks_changes(): void
    {
        // Seeded default (6) is served before any change.
        $response = (new Stash($this->protocolUser(), []))->handle();
        $this->assertSame('6', $response->headers->get('profile-update-interval'));

        admin_setting([SubscribeUpdateIntervalService::SETTING_KEY => 12]);
        $response = (new Stash($this->protocolUser(), []))->handle();
        $this->assertSame('12', $response->headers->get('profile-update-interval'));

        // A further change is visible on the very next response.
        admin_setting([SubscribeUpdateIntervalService::SETTING_KEY => 48]);
        $response = (new Stash($this->protocolUser(), []))->handle();
        $this->assertSame('48', $response->headers->get('profile-update-interval'));
    }

    public function test_shadowrocket_response_has_no_interval_header(): void
    {
        admin_setting([SubscribeUpdateIntervalService::SETTING_KEY => 12]);

        $response = (new Shadowrocket($this->protocolUser(), []))->handle();
        $this->assertFalse($response->headers->has('profile-update-interval'));
    }

    public function test_config_save_rejects_invalid_and_audits_valid_change(): void
    {
        $admin = User::create([
            'email' => 'interval-admin@example.test',
            'password' => 'password',
            'uuid' => '22222222-3333-4444-5555-666666666666',
            'token' => 'interval-admin-token',
            'balance' => 0, 'commission_balance' => 0, 'transfer_enable' => 0,
            'u' => 0, 'd' => 0, 'banned' => 0, 'is_admin' => 1, 'is_staff' => 0,
            'expired_at' => 0, 'remind_expire' => 1, 'remind_traffic' => 1,
            'created_at' => time(), 'updated_at' => time(),
        ]);
        $this->actingAs($admin, 'sanctum');
        $base = '/api/v2/' . hash('crc32b', config('app.key')) . '/config';

        // Out-of-range value: rejected, stored (seeded) value untouched.
        $this->postJson($base . '/save', [
            SubscribeUpdateIntervalService::SETTING_KEY => 100,
        ])->assertStatus(422);
        $this->assertSame(6, app(SubscribeUpdateIntervalService::class)->hours());

        // Non-integer value: also rejected.
        $this->postJson($base . '/save', [
            SubscribeUpdateIntervalService::SETTING_KEY => 'abc',
        ])->assertStatus(422);
        $this->assertSame(6, app(SubscribeUpdateIntervalService::class)->hours());

        // Valid value: saved, visible via fetch, effective immediately.
        $this->postJson($base . '/save', [
            SubscribeUpdateIntervalService::SETTING_KEY => 12,
        ])->assertOk();
        $this->getJson($base . '/fetch?key=subscribe')
            ->assertOk()
            ->assertJsonPath('data.subscribe.subscribe_update_interval_hours', 12);
        $response = (new Clash($this->protocolUser(), []))->handle();
        $this->assertSame('12', $response->headers->get('profile-update-interval'));

        // Audit trail carries operator, old value, new value and result.
        $audit = AdminAuditLog::where('action', 'config.subscribe_update_interval')
            ->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame($admin->id, (int) $audit->admin_id);
        $payload = json_decode($audit->request_data, true);
        $this->assertSame(6, $payload['old_value']);
        $this->assertSame(12, $payload['new_value']);
        $this->assertSame('applied', $payload['result']);
    }
}
