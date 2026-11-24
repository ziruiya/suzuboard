<?php

namespace Tests\Feature\Enterprise;

use App\Models\EnterpriseInventoryKit;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

/**
 * SB-ENT-INV-01 API surface: admin inventory endpoints + the member-center
 * lines endpoint (D05 shape, no secrets).
 */
class EnterpriseApiTest extends EnterpriseTestCase
{
    private function uriBySuffix(string $suffix): string
    {
        foreach (Route::getRoutes() as $route) {
            if (str_ends_with($route->uri(), $suffix)) {
                return '/' . $route->uri();
            }
        }
        $this->fail("route *{$suffix} is not registered");
    }

    public function test_routes_are_registered(): void
    {
        $uris = collect(Route::getRoutes())->map(fn($r) => $r->uri());
        foreach ([
            'suzu/enterprise/purposes',
            'suzu/enterprise/kits',
            'suzu/enterprise/kit',
            'suzu/enterprise/kits/build',
            'suzu/enterprise/kits/confirm-rules',
            'suzu/enterprise/kits/verify',
            'suzu/enterprise/kits/rebuild',
            'suzu/enterprise/kits/revoke',
            'suzu/enterprise/kits/register-legacy',
            'suzu/enterprise/rules',
            'suzu/enterprise/alerts',
            'suzu/enterprise/intents',
            'user/enterprise/lines',
        ] as $suffix) {
            $this->assertTrue(
                $uris->contains(fn($uri) => str_ends_with($uri, $suffix)),
                "route {$suffix} is not registered"
            );
        }
    }

    public function test_admin_api_requires_admin_and_confirm_for_writes(): void
    {
        $admin = $this->makeUser(['is_admin' => 1, 'email' => 'admin@example.com']);
        Sanctum::actingAs($admin);

        $purposes = $this->getJson($this->uriBySuffix('suzu/enterprise/purposes'));
        $purposes->assertOk();
        $codes = collect($purposes->json('data'))->pluck('code');
        $this->assertContains('JP_TIKTOK', $codes);

        // Write without confirm=true is refused (endpoint-module convention).
        $refused = $this->postJson($this->uriBySuffix('suzu/enterprise/kits/build'), [
            'purpose_id' => $this->purpose->id,
        ]);
        $refused->assertStatus(422);
        $this->assertSame('fail', $refused->json('status'));

        $kits = $this->getJson($this->uriBySuffix('suzu/enterprise/kits'));
        $kits->assertOk();
        $this->assertSame([], $kits->json('data'));
    }

    public function test_admin_api_rejects_non_admin(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $response = $this->getJson($this->uriBySuffix('suzu/enterprise/kits'));
        $response->assertForbidden();
    }

    public function test_user_lines_endpoint_shape_and_no_secrets(): void
    {
        $kit = $this->buildReadyKit();
        $this->registerRulePair();
        $user = $this->makeUser();
        $order = OrderService::createFromRequest($user, $this->enterprisePlan, Plan::PERIOD_MONTHLY, null, 'JP_TIKTOK');
        (new OrderService($order))->paid('CB-API');

        Sanctum::actingAs($user->fresh());
        $response = $this->getJson($this->uriBySuffix('user/enterprise/lines'));
        $response->assertOk();
        $data = $response->json('data');
        $this->assertTrue($data['has_enterprise']);
        $this->assertSame('日本-TikTok用', $data['purpose_name']);
        $this->assertSame('ent-jp-tiktok-001.ent.suzu.life', $data['primary']['entry_address']);
        $this->assertNotEmpty($data['primary']['entry_port']);
        $this->assertArrayHasKey('transfer_remaining', $data);
        $this->assertArrayHasKey('device_limit', $data);

        $raw = $response->getContent();
        $this->assertStringNotContainsString($user->uuid, $raw, 'uuid must never be exposed');
        $this->assertStringNotContainsString('password', $raw);
        $this->assertStringNotContainsString('private_key', $raw);
        $this->assertSame(EnterpriseInventoryKit::STATUS_ASSIGNED, $kit->fresh()->status);
    }

    public function test_user_lines_endpoint_for_plain_user(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);
        $response = $this->getJson($this->uriBySuffix('user/enterprise/lines'));
        $response->assertOk();
        $this->assertFalse($response->json('data.has_enterprise'));
    }
}
