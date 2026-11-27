<?php

namespace Tests\Feature\Batch3;

use App\Models\AdminAuditLog;
use App\Models\Payment;
use App\Models\Setting as SettingModel;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\AppRateLimitService;
use App\Support\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attributes = []): User
    {
        return User::create($attributes + [
            'email' => Str::uuid() . '@example.test',
            'password' => bcrypt('test-password'),
            'uuid' => Str::uuid()->toString(),
            'token' => Str::random(32),
        ]);
    }

    public function test_member_quick_login_requires_a_live_issuing_session(): void
    {
        $this->assertQuickLoginRevocation('/api/v1/user/getQuickLoginUrl');
    }

    public function test_passport_quick_login_requires_a_live_issuing_session(): void
    {
        $this->assertQuickLoginRevocation('/api/v1/passport/auth/getQuickLoginUrl');
    }

    private function assertQuickLoginRevocation(string $endpoint): void
    {
        $user = $this->user();
        $session = $user->createToken('issuing session');
        $redeem = '/api/v1/passport/auth/token2Login?verify=';

        $issue = function () use ($endpoint, $session): string {
            Auth::forgetGuards();
            $url = $this->postJson($endpoint, [], [
                'Authorization' => 'Bearer ' . $session->plainTextToken,
            ])->assertOk()->json('data');
            parse_str(substr(parse_url($url, PHP_URL_FRAGMENT), strlen('/login?')), $query);
            $this->assertNotEmpty($query['verify']);
            return $query['verify'];
        };

        $this->getJson($redeem . $issue())->assertOk()->assertJsonStructure(['data' => ['auth_data']]);
        $code = $issue();
        $session->accessToken->delete();
        $this->getJson($redeem . $code)->assertStatus(400);
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_member_without_a_persisted_session_cannot_issue_quick_login(): void
    {
        Sanctum::actingAs($this->user());
        $this->postJson('/api/v1/user/getQuickLoginUrl')->assertUnauthorized();
    }

    public function test_payment_save_redacts_config_keys_in_both_encodings(): void
    {
        config(['v2board.app_url' => 'https://panel.example.test']);
        Sanctum::actingAs($this->user(['is_admin' => 1]));
        $route = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route) => str_ends_with($route->uri(), '/payment/save')
                && str_contains($route->getActionName(), 'V2\\Admin\\'),
        );
        $config = ['url' => 'https://gateway.example.test', 'credentials' => ['key' => 'batch3-test-secret']];

        foreach ([$config, json_encode($config, JSON_THROW_ON_ERROR)] as $index => $payload) {
            $name = 'gateway-' . $index;
            $this->postJson('/' . $route->uri(), [
                'name' => $name, 'payment' => 'EPay', 'config' => $payload,
            ])->assertOk();
            $this->assertSame($payload, Payment::where('name', $name)->firstOrFail()->config);
            $log = AdminAuditLog::latest('id')->firstOrFail();
            $this->assertStringNotContainsString('batch3-test-secret', $log->request_data);
            $data = json_decode($log->request_data, true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame($name, $data['name']);
            $this->assertSame('***', $data['config']['credentials']['key']);
        }
    }

    public function test_ticket_detail_returns_messages_for_the_owner(): void
    {
        $owner = $this->user();
        $ticket = $this->ticket($owner);
        TicketMessage::create(['ticket_id' => $ticket->id, 'user_id' => $owner->id, 'message' => 'Owner message']);
        Sanctum::actingAs($owner);

        $this->getJson('/api/v1/user/ticket/fetch?id=' . $ticket->id)
            ->assertOk()->assertJsonPath('data.message.0.message', 'Owner message');
    }

    public function test_missing_ticket_returns_404(): void
    {
        Sanctum::actingAs($this->user());
        $this->getJson('/api/v1/user/ticket/fetch?id=999999')->assertNotFound();
    }

    public function test_other_users_ticket_returns_404(): void
    {
        $ticket = $this->ticket($this->user());
        Sanctum::actingAs($this->user());
        $this->getJson('/api/v1/user/ticket/fetch?id=' . $ticket->id)->assertNotFound();
    }

    private function ticket(User $owner): Ticket
    {
        return Ticket::create(['user_id' => $owner->id, 'subject' => 'Test ticket', 'level' => 1, 'status' => 0]);
    }

    public function test_registration_reads_stop_register_from_database_when_redis_fails(): void
    {
        $this->failSettingsCache();
        $this->mock(AppRateLimitService::class)->shouldReceive('attempt')->once()->andReturnTrue();
        SettingModel::createOrUpdate('stop_register', '1');

        $this->postJson('/api/v1/passport/auth/register', [
            'email' => 'registration@example.com', 'password' => 'test-password',
        ])->assertStatus(400)->assertJsonPath('message', __('Registration has closed'));
        $this->assertDatabaseMissing('v2_user', ['email' => 'registration@example.com']);
    }

    public function test_registration_fails_closed_when_cache_and_database_policy_reads_fail(): void
    {
        $this->failSettingsCache();
        Schema::rename('v2_settings', 'unavailable_settings');

        $this->postJson('/api/v1/passport/auth/register', [
            'email' => 'registration@example.com', 'password' => 'test-password',
        ])->assertStatus(503);
        $this->assertDatabaseMissing('v2_user', ['email' => 'registration@example.com']);
    }

    private function failSettingsCache(): void
    {
        $store = \Mockery::mock(\Illuminate\Contracts\Cache\Repository::class);
        $store->shouldReceive('rememberForever')->once()->andThrow(new \RuntimeException('settings cache offline'));
        Cache::partialMock()->shouldReceive('store')->with('redis')->once()->andReturn($store);
        app()->forgetInstance(Setting::class);
    }
}
