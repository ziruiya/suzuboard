<?php

namespace Tests\Feature\Passport;

use App\Jobs\SendEmailJob;
use App\Models\AdminAuditLog;
use App\Services\CaptchaService;
use App\Services\EmailVerifyGuardService;
use App\Support\Setting;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmailVerifyGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();
        // See LocalCaptchaFlowTest for why the redis store is swapped.
        config()->set('cache.stores.redis', ['driver' => 'array']);
        Cache::forgetDriver('redis');
        app()->forgetInstance(Setting::class);

        admin_setting([
            'captcha_enable' => 1,
            'captcha_type' => 'turnstile',
            'email_whitelist_enable' => 0,
            'app_captcha_mode' => 'exempt',
        ]);
    }

    private function send(string $email)
    {
        return $this->withHeaders(['X-Suzu-App' => '1'])
            ->postJson('/api/v1/passport/comm/sendEmailVerify', ['email' => $email]);
    }

    public function test_domain_limit_blocks_the_same_domain_above_the_cap(): void
    {
        admin_setting([EmailVerifyGuardService::SETTING_DOMAIN_LIMIT => 2]);

        $this->send('one@shared.example')->assertOk();
        $this->send('two@shared.example')->assertOk();
        $this->send('three@shared.example')->assertStatus(429);
        // A different domain is unaffected by the first domain's cap.
        $this->send('other@elsewhere.example')->assertOk();
    }

    public function test_global_budget_blocks_above_the_cap_regardless_of_domain(): void
    {
        admin_setting([EmailVerifyGuardService::SETTING_GLOBAL_LIMIT => 3]);

        $this->send('a@one.example')->assertOk();
        $this->send('b@two.example')->assertOk();
        $this->send('c@three.example')->assertOk();
        $this->send('d@four.example')->assertStatus(429);
    }

    public function test_anomaly_tripwire_counts_rejected_challenge_without_spending_send_budget(): void
    {
        admin_setting([
            EmailVerifyGuardService::SETTING_ANOMALY_THRESHOLD => 2,
            'app_captcha_mode' => 'local',
        ]);

        $this->send('a@one.example')->assertStatus(400);

        $this->assertSame('local', CaptchaService::appCaptchaMode());
        $this->assertNull(Cache::get(CacheKey::get('APP_EMAIL_VERIFY_GLOBAL_RATE_LIMIT', 'ALL')));
        $this->assertNull(Cache::get(CacheKey::get('APP_EMAIL_VERIFY_DOMAIN_RATE_LIMIT', 'one.example')));
        $this->assertNull(Cache::get(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', 'a@one.example')));
        $this->assertSame(1, Cache::get(CacheKey::get('APP_EMAIL_VERIFY_ANOMALY_RATE_LIMIT', 'ALL')));
        Queue::assertNothingPushed();
        $this->assertDatabaseMissing('v2_admin_audit_log', ['action' => EmailVerifyGuardService::AUDIT_ACTION_AUTO_FLIP]);
    }

    public function test_address_cooldown_leaves_budget_for_another_address(): void
    {
        admin_setting([EmailVerifyGuardService::SETTING_GLOBAL_LIMIT => 2]);

        $this->send('a@one.example')->assertOk();
        $this->send('a@one.example')->assertStatus(400);
        $this->send('b@one.example')->assertOk();
    }

    public function test_anomaly_tripwire_flips_on_rejected_challenges_and_writes_one_audit_row(): void
    {
        admin_setting([
            EmailVerifyGuardService::SETTING_ANOMALY_THRESHOLD => 2,
            EmailVerifyGuardService::SETTING_ANOMALY_WINDOW => 90,
            'app_captcha_mode' => 'local',
        ]);

        $this->send('a@one.example')->assertStatus(400);
        $this->send('a@one.example')->assertStatus(400);
        $this->assertSame('turnstile', CaptchaService::appCaptchaMode());

        $this->send('a@one.example')->assertStatus(400);
        $rows = AdminAuditLog::where('action', EmailVerifyGuardService::AUDIT_ACTION_AUTO_FLIP)->get();
        $this->assertCount(1, $rows);
        $data = json_decode($rows->sole()->request_data, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([
            'key' => 'app_captcha_mode',
            'old_value' => 'local',
            'new_value' => 'turnstile',
            'window_seconds' => 90,
            'window_count' => 2,
            'threshold' => 2,
            'trigger_ip' => '127.0.0.1',
            'result' => 'applied',
        ], $data);
        $this->travel(91)->seconds();
        $this->assertSame('turnstile', CaptchaService::appCaptchaMode());
    }

    public function test_anomaly_tripwire_does_not_flip_when_already_turnstile(): void
    {
        admin_setting([
            EmailVerifyGuardService::SETTING_ANOMALY_THRESHOLD => 1,
            'app_captcha_mode' => 'turnstile',
        ]);

        $this->send('a@one.example')->assertStatus(400);

        $this->assertSame('turnstile', CaptchaService::appCaptchaMode());
        $this->assertSame(1, Cache::get(CacheKey::get('APP_EMAIL_VERIFY_ANOMALY_RATE_LIMIT', 'ALL')));
        $this->assertDatabaseMissing('v2_admin_audit_log', ['action' => EmailVerifyGuardService::AUDIT_ACTION_AUTO_FLIP]);
    }

    public function test_successful_send_reserves_budget_and_triggers_anomaly_flip(): void
    {
        admin_setting([EmailVerifyGuardService::SETTING_ANOMALY_THRESHOLD => 1]);

        $this->send('a@one.example')->assertOk();

        Queue::assertPushed(SendEmailJob::class, 1);
        $this->assertNotNull(Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', 'a@one.example')));
        $this->assertSame(1, Cache::get(CacheKey::get('APP_EMAIL_VERIFY_GLOBAL_RATE_LIMIT', 'ALL')));
        $this->assertSame(1, Cache::get(CacheKey::get('APP_EMAIL_VERIFY_DOMAIN_RATE_LIMIT', 'one.example')));
        $this->assertSame(1, Cache::get(CacheKey::get('APP_EMAIL_VERIFY_ANOMALY_RATE_LIMIT', 'ALL')));
        $this->assertSame('turnstile', CaptchaService::appCaptchaMode());
        $this->assertDatabaseHas('v2_admin_audit_log', [
            'action' => EmailVerifyGuardService::AUDIT_ACTION_AUTO_FLIP,
        ]);
    }
}
