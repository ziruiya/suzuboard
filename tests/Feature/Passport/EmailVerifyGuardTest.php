<?php

namespace Tests\Feature\Passport;

use App\Models\AdminAuditLog;
use App\Services\CaptchaService;
use App\Services\EmailVerifyGuardService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AUD-001 Part A: layered rate limits on sendEmailVerify (per-domain
 * and site-wide, on top of the unchanged per-IP limit) and the anomaly
 * tripwire that flips app_captcha_mode to 'turnstile'.
 */
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

    public function test_anomaly_tripwire_flips_mode_to_turnstile_and_writes_an_audit_row(): void
    {
        admin_setting([EmailVerifyGuardService::SETTING_ANOMALY_THRESHOLD => 3]);

        $this->send('a@one.example')->assertOk();
        $this->send('b@two.example')->assertOk();
        $this->assertSame('exempt', CaptchaService::appCaptchaMode());
        $this->assertSame(0, AdminAuditLog::where('action', EmailVerifyGuardService::AUDIT_ACTION_AUTO_FLIP)->count());

        // The third attempt crosses the threshold: the flip happens
        // inside this request, so the request itself now faces
        // Turnstile and fails - the brake engaging is the point.
        $this->send('c@three.example')->assertStatus(400);

        $this->assertSame('turnstile', CaptchaService::appCaptchaMode());
        $row = AdminAuditLog::where('action', EmailVerifyGuardService::AUDIT_ACTION_AUTO_FLIP)->first();
        $this->assertNotNull($row);
        $data = json_decode((string) $row->request_data, true);
        $this->assertSame('exempt', $data['old_value']);
        $this->assertSame('turnstile', $data['new_value']);
        $this->assertSame(3, $data['window_count']);
    }

    public function test_anomaly_tripwire_stays_quiet_below_the_threshold(): void
    {
        admin_setting([EmailVerifyGuardService::SETTING_ANOMALY_THRESHOLD => 5]);

        $this->send('a@one.example')->assertOk();
        $this->send('b@two.example')->assertOk();
        $this->send('c@three.example')->assertOk();

        $this->assertSame('exempt', CaptchaService::appCaptchaMode());
        $this->assertSame(0, AdminAuditLog::where('action', EmailVerifyGuardService::AUDIT_ACTION_AUTO_FLIP)->count());
    }

    public function test_anomaly_tripwire_does_not_flip_when_already_turnstile(): void
    {
        admin_setting([
            EmailVerifyGuardService::SETTING_ANOMALY_THRESHOLD => 2,
            'app_captcha_mode' => 'turnstile',
        ]);

        // Requests fail Turnstile (no token), but the attempts are
        // still counted; no flip and no audit row may be produced.
        $this->send('a@one.example')->assertStatus(400);
        $this->send('b@two.example')->assertStatus(400);

        $this->assertSame('turnstile', CaptchaService::appCaptchaMode());
        $this->assertSame(0, AdminAuditLog::where('action', EmailVerifyGuardService::AUDIT_ACTION_AUTO_FLIP)->count());
    }
}
