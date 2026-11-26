<?php

namespace Tests\Unit\Services\Auth;

use App\Models\User;
use App\Services\Auth\LoginService;
use App\Support\Setting;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class LoginServiceTest extends TestCase
{
    use RefreshDatabase;

    private LoginService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->service = app(LoginService::class);
    }

    public function test_reset_password_rejects_missing_cached_email_code(): void
    {
        $user = $this->createUser('victim@example.com', 'old-password');

        [$success, $result] = $this->service->resetPassword($user->email, '', 'new-password');

        $this->assertFalse($success);
        $this->assertSame(400, $result[0]);

        $user->refresh();
        $this->assertTrue(password_verify('old-password', $user->password));
    }

    public function test_reset_password_accepts_matching_cached_email_code(): void
    {
        $user = $this->createUser('user@example.com', 'old-password');
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $user->email), 123456, 300);

        [$success, $result] = $this->service->resetPassword($user->email, '123456', 'new-password');

        $this->assertTrue($success);
        $this->assertTrue($result);

        $user->refresh();
        $this->assertTrue(password_verify('new-password', $user->password));
        $this->assertNull(Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $user->email)));
    }

    public function test_reset_password_uses_canonical_email_for_verification_code(): void
    {
        $user = $this->createUser('José@example.com', 'old-password');
        $email = "JOSE\u{0301}@EXAMPLE.COM";
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', User::normalizeEmail($email)), 123456, 300);

        [$success, $result] = $this->service->resetPassword($email, '123456', 'new-password');

        $this->assertTrue($success);
        $this->assertTrue($result);
        $this->assertTrue(password_verify('new-password', $user->fresh()->password));
    }

    public function test_forget_password_validation_rejects_boolean_email_code(): void
    {
        $validator = Validator::make([
            'email' => 'victim@example.com',
            'password' => 'new-password',
            'email_code' => false,
        ], [
            'email' => 'required|email:strict',
            'password' => 'required|min:8',
            'email_code' => 'required|digits:6',
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('email_code', $validator->errors()->toArray());
    }

    public function test_credential_budget_is_recorded_before_account_lookup(): void
    {
        config()->set('cache.stores.redis', ['driver' => 'array']);
        Cache::forgetDriver('redis');
        app()->forgetInstance(Setting::class);
        admin_setting(['password_limit_enable' => 1, 'password_limit_count' => 1]);

        [, $result] = $this->service->login('missing@example.com', 'any-password');
        $this->assertSame(400, $result[0]);
        $this->assertSame(1, Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', 'missing@example.com')));
    }

    public function test_reset_reserves_budget_before_successful_validation(): void
    {
        $user = $this->createUser('reserve@example.com', 'old-password');
        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $user->email), 123456, 300);

        $this->assertSame([true, true], $this->service->resetPassword($user->email, '123456', 'new-password'));
        $this->assertSame(1, Cache::get(CacheKey::get('FORGET_REQUEST_LIMIT', $user->email)));
    }

    private function createUser(string $email, string $password): User
    {
        return User::query()->create([
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'created_at' => time(),
            'updated_at' => time(),
        ]);
    }
}