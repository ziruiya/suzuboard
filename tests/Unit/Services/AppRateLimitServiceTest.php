<?php

namespace Tests\Unit\Services;

use App\Services\AppRateLimitService;
use App\Services\Auth\RegisterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class AppRateLimitServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_attempt_allows_up_to_the_limit_then_blocks(): void
    {
        $service = app(AppRateLimitService::class);

        for ($i = 1; $i <= 3; $i++) {
            $this->assertTrue($service->attempt('APP_REGISTER_IP_RATE_LIMIT', '203.0.113.7', 3), "attempt {$i} should pass");
        }
        $this->assertFalse($service->attempt('APP_REGISTER_IP_RATE_LIMIT', '203.0.113.7', 3));
    }

    public function test_attempt_buckets_and_ips_are_independent(): void
    {
        $service = app(AppRateLimitService::class);

        $this->assertTrue($service->attempt('APP_REGISTER_IP_RATE_LIMIT', '203.0.113.7', 1));
        $this->assertFalse($service->attempt('APP_REGISTER_IP_RATE_LIMIT', '203.0.113.7', 1));
        // Different IP in the same bucket is unaffected
        $this->assertTrue($service->attempt('APP_REGISTER_IP_RATE_LIMIT', '203.0.113.8', 1));
        // Same IP in a different bucket is unaffected
        $this->assertTrue($service->attempt('APP_EMAIL_VERIFY_IP_RATE_LIMIT', '203.0.113.7', 1));
    }

    public function test_register_validation_is_rate_limited_per_ip_per_hour(): void
    {
        admin_setting([
            'captcha_enable' => 0,
            'email_verify' => 0,
            'email_whitelist_enable' => 0,
            'email_gmail_limit_enable' => 0,
            'stop_register' => 0,
            'invite_force' => 0,
            'register_limit_by_ip_enable' => 0,
        ]);

        $service = app(RegisterService::class);
        $makeRequest = function (int $i): Request {
            return Request::create('/api/v1/passport/auth/register', 'POST', [
                'email' => "ratelimit{$i}@example.com",
                'password' => 'password123',
            ], [], [], ['REMOTE_ADDR' => '203.0.113.9']);
        };

        for ($i = 1; $i <= 50; $i++) {
            [$success, $result] = $service->validateRegister($makeRequest($i));
            $this->assertTrue($success, "attempt {$i} should validate, got: " . json_encode($result));
        }

        [$success, $result] = $service->validateRegister($makeRequest(51));
        $this->assertFalse($success);
        $this->assertSame(429, $result[0]);
    }
}
