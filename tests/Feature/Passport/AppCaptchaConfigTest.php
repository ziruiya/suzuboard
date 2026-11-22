<?php

namespace Tests\Feature\Passport;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AppCaptchaConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();
        admin_setting([
            'captcha_enable' => 1,
            'captcha_type' => 'turnstile',
            'email_whitelist_enable' => 0,
        ]);
    }

    public function test_comm_config_outputs_mode_and_derived_exempt_flag(): void
    {
        admin_setting(['app_captcha_mode' => 'exempt']);

        $response = $this->getJson('/api/v1/guest/comm/config');
        $response->assertOk();
        $response->assertJsonPath('data.app_captcha_mode', 'exempt');
        $response->assertJsonPath('data.app_captcha_exempt', 1);

        admin_setting(['app_captcha_mode' => 'turnstile']);

        $response = $this->getJson('/api/v1/guest/comm/config');
        $response->assertOk();
        $response->assertJsonPath('data.app_captcha_mode', 'turnstile');
        $response->assertJsonPath('data.app_captcha_exempt', 0);
    }

    public function test_send_email_verify_with_app_header_in_exempt_mode_succeeds_without_token(): void
    {
        admin_setting(['app_captcha_mode' => 'exempt']);

        $response = $this->withHeaders(['X-Suzu-App' => '1'])
            ->postJson('/api/v1/passport/comm/sendEmailVerify', [
                'email' => 'appuser@example.com',
            ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
    }

    public function test_send_email_verify_without_app_header_is_rejected_by_captcha(): void
    {
        admin_setting(['app_captcha_mode' => 'exempt']);

        $response = $this->postJson('/api/v1/passport/comm/sendEmailVerify', [
            'email' => 'webuser@example.com',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('status', 'fail');
    }

    public function test_send_email_verify_with_app_header_in_turnstile_mode_is_rejected_by_captcha(): void
    {
        admin_setting(['app_captcha_mode' => 'turnstile']);

        $response = $this->withHeaders(['X-Suzu-App' => '1'])
            ->postJson('/api/v1/passport/comm/sendEmailVerify', [
                'email' => 'appuser@example.com',
            ]);

        $response->assertStatus(400);
        $response->assertJsonPath('status', 'fail');
    }

    public function test_send_email_verify_is_rate_limited_per_ip_per_hour(): void
    {
        admin_setting(['app_captcha_mode' => 'exempt']);

        for ($i = 1; $i <= 30; $i++) {
            $response = $this->withHeaders(['X-Suzu-App' => '1'])
                ->postJson('/api/v1/passport/comm/sendEmailVerify', [
                    'email' => "bulk{$i}@example.com",
                ]);
            $response->assertOk("request {$i} should pass");
        }

        $response = $this->withHeaders(['X-Suzu-App' => '1'])
            ->postJson('/api/v1/passport/comm/sendEmailVerify', [
                'email' => 'bulk31@example.com',
            ]);
        $response->assertStatus(429);
        $response->assertJsonPath('status', 'fail');
    }
}
