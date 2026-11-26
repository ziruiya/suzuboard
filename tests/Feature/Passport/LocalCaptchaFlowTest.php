<?php

namespace Tests\Feature\Passport;

use App\Services\CaptchaService;
use App\Services\LocalCaptchaService;
use App\Support\Setting;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * AUD-001 local captcha mode: challenge endpoint, the sendEmailVerify /
 * register verification branches, and the machine error codes the App
 * maps to UI behaviour (update prompt vs inline refresh).
 */
class LocalCaptchaFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();
        // App\Support\Setting hardcodes Cache::store('redis'); the test
        // environment has no Redis (same reason the older captcha tests
        // are environmental baseline failures), so back it with array.
        config()->set('cache.stores.redis', ['driver' => 'array']);
        Cache::forgetDriver('redis');
        app()->forgetInstance(Setting::class);

        admin_setting([
            'captcha_enable' => 1,
            'captcha_type' => 'turnstile',
            'email_whitelist_enable' => 0,
            'email_verify' => 0,
            'email_gmail_limit_enable' => 0,
            'stop_register' => 0,
            'invite_force' => 0,
            'register_limit_by_ip_enable' => 0,
            'app_captcha_mode' => 'local',
        ]);
    }

    private function seedChallenge(string $id, string $code): void
    {
        Cache::put(
            CacheKey::get('LOCAL_CAPTCHA_CHALLENGE', $id),
            hash('sha256', strtoupper($code)),
            LocalCaptchaService::TTL_SECONDS
        );
    }

    private function appPost(string $uri, array $data)
    {
        return $this->withHeaders(['X-Suzu-App' => '1'])->postJson($uri, $data);
    }

    public function test_challenge_endpoint_issues_a_png_data_uri(): void
    {
        $response = $this->getJson('/api/v1/guest/comm/captcha');

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $id = $response->json('data.captcha_id');
        $this->assertNotEmpty($id);
        $this->assertSame(LocalCaptchaService::TTL_SECONDS, $response->json('data.expires_in'));

        $image = $response->json('data.captcha_image');
        $this->assertStringStartsWith('data:image/png;base64,', $image);
        $png = base64_decode(substr($image, strlen('data:image/png;base64,')), true);
        $this->assertNotFalse($png);
        $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $png);

        // The challenge really was stored server-side (hash only).
        $this->assertIsString(Cache::get(CacheKey::get('LOCAL_CAPTCHA_CHALLENGE', $id)));
    }

    public function test_non_local_mode_skips_challenge_generation(): void
    {
        admin_setting(['app_captcha_mode' => 'exempt']);
        $issuer = $this->mock(LocalCaptchaService::class);
        $issuer->shouldReceive('issue')->andReturn([
            'id' => 'challenge-stub',
            'image' => 'data:image/png;base64,',
            'expires_in' => LocalCaptchaService::TTL_SECONDS,
        ]);

        $this->getJson('/api/v1/guest/comm/captcha')->assertNotFound();
        $issuer->shouldNotHaveReceived('issue');
    }

    public function test_capped_source_skips_challenge_generation(): void
    {
        Cache::put(CacheKey::get('LOCAL_CAPTCHA_IP_RATE_LIMIT', '127.0.0.1'), 15, 60);
        $issuer = $this->mock(LocalCaptchaService::class);
        $issuer->shouldReceive('issue')->andReturn([
            'id' => 'challenge-stub',
            'image' => 'data:image/png;base64,',
            'expires_in' => LocalCaptchaService::TTL_SECONDS,
        ]);

        $this->getJson('/api/v1/guest/comm/captcha')->assertTooManyRequests();
        $issuer->shouldNotHaveReceived('issue');
        $this->assertNull(Cache::get(CacheKey::get('LOCAL_CAPTCHA_GLOBAL_RATE_LIMIT', 'ALL')));
    }

    public function test_capped_global_budget_skips_challenge_generation(): void
    {
        Cache::put(CacheKey::get('LOCAL_CAPTCHA_GLOBAL_RATE_LIMIT', 'ALL'), 300, 60);
        $issuer = $this->mock(LocalCaptchaService::class);
        $issuer->shouldReceive('issue')->andReturn([
            'id' => 'challenge-stub',
            'image' => 'data:image/png;base64,',
            'expires_in' => LocalCaptchaService::TTL_SECONDS,
        ]);

        $this->getJson('/api/v1/guest/comm/captcha')->assertTooManyRequests();
        $issuer->shouldNotHaveReceived('issue');
    }

    public function test_send_email_verify_without_captcha_fields_reports_app_captcha_required(): void
    {
        $response = $this->appPost('/api/v1/passport/comm/sendEmailVerify', [
            'email' => 'oldapp@example.com',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('status', 'fail');
        $response->assertJsonPath('data.captcha_error_code', CaptchaService::ERR_APP_CAPTCHA_REQUIRED);
    }

    public function test_send_email_verify_with_correct_answer_succeeds(): void
    {
        $this->seedChallenge('flow-ok', 'AB3K');

        $response = $this->appPost('/api/v1/passport/comm/sendEmailVerify', [
            'email' => 'newapp@example.com',
            'captcha_id' => 'flow-ok',
            'captcha_answer' => 'ab3k',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
    }

    public function test_send_email_verify_with_wrong_answer_reports_captcha_invalid(): void
    {
        $this->seedChallenge('flow-wrong', 'AB3K');

        $response = $this->appPost('/api/v1/passport/comm/sendEmailVerify', [
            'email' => 'newapp@example.com',
            'captcha_id' => 'flow-wrong',
            'captcha_answer' => 'ZZZZ',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('data.captcha_error_code', CaptchaService::ERR_CAPTCHA_INVALID);
    }

    public function test_send_email_verify_with_unknown_challenge_reports_captcha_expired(): void
    {
        $response = $this->appPost('/api/v1/passport/comm/sendEmailVerify', [
            'email' => 'newapp@example.com',
            'captcha_id' => 'never-issued',
            'captcha_answer' => 'AB3K',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('data.captcha_error_code', CaptchaService::ERR_CAPTCHA_EXPIRED);
    }

    public function test_send_email_verify_challenge_cannot_be_replayed(): void
    {
        $this->seedChallenge('flow-replay', 'AB3K');

        $first = $this->appPost('/api/v1/passport/comm/sendEmailVerify', [
            'email' => 'first@example.com',
            'captcha_id' => 'flow-replay',
            'captcha_answer' => 'AB3K',
        ]);
        $first->assertOk();

        $second = $this->appPost('/api/v1/passport/comm/sendEmailVerify', [
            'email' => 'second@example.com',
            'captcha_id' => 'flow-replay',
            'captcha_answer' => 'AB3K',
        ]);
        $second->assertStatus(400);
        $second->assertJsonPath('data.captcha_error_code', CaptchaService::ERR_CAPTCHA_EXPIRED);
    }

    public function test_web_request_in_local_mode_keeps_the_turnstile_flow_and_no_machine_code(): void
    {
        $response = $this->postJson('/api/v1/passport/comm/sendEmailVerify', [
            'email' => 'webuser@example.com',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('status', 'fail');
        $this->assertEmpty($response->json('data.captcha_error_code'));
    }

    public function test_register_without_captcha_fields_reports_app_captcha_required(): void
    {
        $response = $this->appPost('/api/v1/passport/auth/register', [
            'email' => 'oldapp@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('data.captcha_error_code', CaptchaService::ERR_APP_CAPTCHA_REQUIRED);
    }

    public function test_register_with_correct_answer_succeeds(): void
    {
        $this->seedChallenge('reg-ok', 'AB3K');

        $response = $this->appPost('/api/v1/passport/auth/register', [
            'email' => 'newuser@example.com',
            'password' => 'password123',
            'captcha_id' => 'reg-ok',
            'captcha_answer' => 'AB3K',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
        $this->assertDatabaseHas('v2_user', ['email' => 'newuser@example.com']);
    }

    public function test_exempt_mode_still_skips_captcha_for_app_requests(): void
    {
        admin_setting(['app_captcha_mode' => 'exempt']);

        $response = $this->appPost('/api/v1/passport/comm/sendEmailVerify', [
            'email' => 'appuser@example.com',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'success');
    }

    public function test_turnstile_mode_still_rejects_app_requests_without_token(): void
    {
        admin_setting(['app_captcha_mode' => 'turnstile']);

        $response = $this->appPost('/api/v1/passport/comm/sendEmailVerify', [
            'email' => 'appuser@example.com',
            'captcha_id' => 'whatever',
            'captcha_answer' => 'AB3K',
        ]);

        $response->assertStatus(400);
        $this->assertEmpty($response->json('data.captcha_error_code'));
    }
}
