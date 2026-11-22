<?php

namespace Tests\Unit\Services;

use App\Services\CaptchaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CaptchaServiceTest extends TestCase
{
    use RefreshDatabase;

    private CaptchaService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        admin_setting([
            'captcha_enable' => 1,
            'captcha_type' => 'turnstile',
        ]);

        $this->service = app(CaptchaService::class);
    }

    private function makeRequest(bool $withAppHeader): Request
    {
        $request = Request::create('/api/v1/passport/comm/sendEmailVerify', 'POST', [
            'email' => 'user@example.com',
        ]);
        if ($withAppHeader) {
            $request->headers->set('X-Suzu-App', '1');
        }
        return $request;
    }

    public function test_exempt_mode_with_app_header_skips_verification(): void
    {
        admin_setting(['app_captcha_mode' => 'exempt']);

        // No turnstile token at all: without the exemption this fails.
        [$valid, $error] = $this->service->verify($this->makeRequest(true));

        $this->assertTrue($valid);
        $this->assertNull($error);
    }

    public function test_exempt_mode_without_app_header_still_verifies(): void
    {
        admin_setting(['app_captcha_mode' => 'exempt']);

        [$valid, $error] = $this->service->verify($this->makeRequest(false));

        $this->assertFalse($valid);
        $this->assertSame(400, $error[0]);
    }

    public function test_turnstile_mode_with_app_header_still_verifies(): void
    {
        admin_setting(['app_captcha_mode' => 'turnstile']);

        [$valid, $error] = $this->service->verify($this->makeRequest(true));

        $this->assertFalse($valid);
        $this->assertSame(400, $error[0]);
    }

    public function test_missing_mode_setting_defaults_to_turnstile(): void
    {
        // app_captcha_mode was never written in this test's settings.
        $this->assertSame('turnstile', CaptchaService::appCaptchaMode());

        [$valid, $error] = $this->service->verify($this->makeRequest(true));

        $this->assertFalse($valid);
        $this->assertSame(400, $error[0]);
    }

    public function test_local_mode_is_reserved_and_does_not_exempt(): void
    {
        admin_setting(['app_captcha_mode' => 'local']);

        $this->assertSame('local', CaptchaService::appCaptchaMode());

        [$valid, $error] = $this->service->verify($this->makeRequest(true));

        $this->assertFalse($valid);
        $this->assertSame(400, $error[0]);
    }

    public function test_invalid_mode_value_falls_back_to_turnstile(): void
    {
        admin_setting(['app_captcha_mode' => 'bogus']);

        $this->assertSame('turnstile', CaptchaService::appCaptchaMode());
        $this->assertFalse(CaptchaService::isAppCaptchaExempt($this->makeRequest(true)));
    }
}
