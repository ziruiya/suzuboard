<?php

namespace Tests\Feature\Passport;

use App\Models\User;
use App\Services\Auth\LoginService;
use App\Services\Auth\MailLinkService;
use App\Services\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * SB-TA batch 2 (auth group):
 *  - TA-012: a pre-signed quick-login ticket is bound to its issuing
 *    session and the account auth version; revoking the session or
 *    rotating credentials (password reset) kills outstanding tickets,
 *    and a ticket can be redeemed exactly once.
 *  - TA-013: an admin password change revokes the target's tokens and
 *    bumps the auth version in the same commit.
 *  - TA-014: a ban commits together with the session revocation, and
 *    the Admin middleware rejects banned users outright.
 */
class AuthTicketRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        \Mockery::close();
        DB::purge();
        DB::disconnect();
        parent::tearDown();
    }

    protected function setUp(): void
    {
        try {
            DB::purge();
            DB::disconnect();
        } catch (\Throwable $e) {
        }
        gc_collect_cycles();

        parent::setUp();
        Cache::flush();
    }

    private function makeUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'email' => 'ta-auth-' . uniqid() . '@example.com',
            'password' => password_hash('password123', PASSWORD_DEFAULT),
            'uuid' => '00000000-0000-0000-0000-0000000006' . random_int(10, 99),
            'token' => bin2hex(random_bytes(16)),
            'balance' => 0,
            'commission_balance' => 0,
            'transfer_enable' => 0,
            'u' => 0,
            'd' => 0,
            'banned' => 0,
            'is_admin' => 0,
            'is_staff' => 0,
            'expired_at' => 0,
            'remind_expire' => 1,
            'remind_traffic' => 1,
            'created_at' => time(),
            'updated_at' => time(),
        ], $overrides));
    }

    private function quickLoginCode(User $user, ?int $sessionId): string
    {
        $url = (new LoginService())->generateQuickLoginUrl($user, 'dashboard', $sessionId);
        $this->assertNotNull($url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        // The URL embeds the code in the fragment-style redirect target.
        $fragment = (string) parse_url($url, PHP_URL_FRAGMENT);
        parse_str(str_replace('/login?', '', $fragment), $fragQuery);
        $code = $fragQuery['verify'] ?? $query['verify'] ?? null;
        $this->assertNotEmpty($code, 'quick login URL must carry a verify code: ' . $url);
        return (string) $code;
    }

    private function uriBySuffix(string $suffix): string
    {
        // Several routes share the suffix (V1 user routes vs the V2
        // admin surface); the admin one is the route under test.
        foreach (Route::getRoutes() as $route) {
            if (str_ends_with($route->uri(), $suffix)
                && str_contains((string) $route->getActionName(), 'V2\\Admin\\')) {
                return '/' . $route->uri();
            }
        }
        $this->fail("admin route *{$suffix} is not registered");
    }

    // ---------------- TA-012 ----------------

    public function test_quick_login_ticket_redeems_once_and_dies_with_its_session(): void
    {
        $user = $this->makeUser();
        $session = $user->createToken('device-a');
        $sessionId = (int) $session->accessToken->id;

        // Happy path: redeem once.
        $code = $this->quickLoginCode($user, $sessionId);
        $this->assertSame($user->id, (new MailLinkService())->handleTokenLogin($code));

        // One-time: the same code cannot be redeemed twice.
        $this->assertNull((new MailLinkService())->handleTokenLogin($code));

        // A fresh ticket bound to the session dies when the session is
        // revoked (the original TA-012 trigger: revoke first, redeem
        // after, inside the ticket's validity window).
        $code2 = $this->quickLoginCode($user->fresh(), $sessionId);
        $user->tokens()->where('id', $sessionId)->delete();
        $this->assertNull((new MailLinkService())->handleTokenLogin($code2));
    }

    public function test_ticket_dies_after_password_reset(): void
    {
        $user = $this->makeUser();
        $session = $user->createToken('device-b');
        $sessionId = (int) $session->accessToken->id;

        $code = $this->quickLoginCode($user, $sessionId);

        // User resets the password (email-code flow).
        Cache::put(\App\Utils\CacheKey::get('EMAIL_VERIFY_CODE', $user->email), '654321', 300);
        [$success] = (new LoginService())->resetPassword($user->email, '654321', 'newpassword456');
        $this->assertTrue($success);

        // The pre-reset ticket must not rebuild a session.
        $this->assertNull((new MailLinkService())->handleTokenLogin($code));
        $this->assertSame(0, $user->fresh()->tokens()->count());
        $this->assertSame(1, (int) $user->fresh()->auth_version);
    }

    public function test_mail_link_ticket_dies_after_credential_rotation(): void
    {
        $user = $this->makeUser();

        // Issue a mail-link style ticket (no issuing session).
        $code = \App\Utils\Helper::guid();
        Cache::put(\App\Utils\CacheKey::get('TEMP_TOKEN', $code), MailLinkService::ticketPayload($user, null), 300);

        (new AuthService($user))->revokeAllSessionsAndBumpAuthVersion();

        $this->assertNull((new MailLinkService())->handleTokenLogin($code));
    }

    // ---------------- TA-013 ----------------

    public function test_admin_password_change_revokes_target_tokens(): void
    {
        $admin = $this->makeUser(['is_admin' => 1, 'email' => 'ta-admin@example.com']);
        $target = $this->makeUser();
        $plain = $target->createToken('target-device')->plainTextToken;
        $this->assertNotNull(AuthService::findUserByBearerToken($plain));

        Sanctum::actingAs($admin);
        $response = $this->postJson($this->uriBySuffix('user/update'), [
            'id' => $target->id,
            'password' => 'brandnewpass1',
        ]);
        $response->assertOk();

        $fresh = $target->fresh();
        $this->assertSame(0, $fresh->tokens()->count());
        $this->assertSame(1, (int) $fresh->auth_version);
        $this->assertNull(AuthService::findUserByBearerToken($plain));
        $this->assertTrue(password_verify('brandnewpass1', $fresh->password));
    }

    // ---------------- TA-014 ----------------

    public function test_admin_ban_revokes_sessions_and_middleware_rejects_banned_admin(): void
    {
        $admin = $this->makeUser(['is_admin' => 1, 'email' => 'ta-admin2@example.com']);
        $targetAdmin = $this->makeUser(['is_admin' => 1]);
        $targetAdmin->createToken('target-admin-device');

        Sanctum::actingAs($admin);
        $response = $this->postJson($this->uriBySuffix('user/update'), [
            'id' => $targetAdmin->id,
            'banned' => true,
        ]);
        $response->assertOk();

        $fresh = $targetAdmin->fresh();
        $this->assertSame(1, (int) $fresh->banned);
        $this->assertSame(0, $fresh->tokens()->count());
        $this->assertSame(1, (int) $fresh->auth_version);

        // Even holding a (hypothetically surviving) session, a banned
        // admin is rejected by the Admin middleware.
        Sanctum::actingAs($fresh);
        $denied = $this->postJson($this->uriBySuffix('user/fetch'), []);
        $denied->assertStatus(403);
    }

    public function test_generate_auth_data_refuses_freshly_banned_user(): void
    {
        $user = $this->makeUser();
        $stale = User::findOrFail($user->id);

        // The ban lands after the caller loaded (and checked) its copy.
        DB::table('v2_user')->where('id', $user->id)->update(['banned' => 1]);

        $this->expectException(\App\Exceptions\ApiException::class);
        (new AuthService($stale))->generateAuthData();
    }
}
