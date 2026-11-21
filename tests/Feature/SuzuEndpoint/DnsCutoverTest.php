<?php

namespace Tests\Feature\SuzuEndpoint;

use App\Exceptions\SuzuEndpointException;
use App\Models\SuzuEndpointBinding;
use App\Models\SuzuEndpointChange;
use App\Models\SuzuEndpointContract;
use App\Services\SuzuDnsCutoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DnsCutoverTest extends TestCase
{
    use RefreshDatabase;

    private SuzuDnsCutoverService $dns;
    private SuzuEndpointBinding $binding;

    private const RECORD_URL = 'https://api.cloudflare.com/client/v4/zones/zone-1/dns_records/rec-1';
    private const OLD_IP = '192.0.2.10';
    private const NEW_IP = '198.51.100.20';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dns = app(SuzuDnsCutoverService::class);
        config()->set('suzu_endpoint.cloudflare.api_token', 'test-token');
        config()->set('suzu_endpoint.cloudflare.base_url', 'https://api.cloudflare.com/client/v4');

        $contract = SuzuEndpointContract::create([
            'logical_node_id' => 999,
            'version' => 1,
            'legacy_hostname' => 'node.example.test',
            'client_port' => 8443,
            'server_port' => 8443,
            'protocol' => 'vless',
            'transport' => 'tcp',
            'auth_namespace' => 'user-uuid',
            'contract_hash' => hash('sha256', 'fixture'),
            'status' => 'active',
        ]);
        $this->binding = SuzuEndpointBinding::create([
            'contract_id' => $contract->id,
            'entry_mode' => 'dns',
            'dns_zone_id' => 'zone-1',
            'dns_record_id' => 'rec-1',
            'current_entry_ip' => self::OLD_IP,
            'health_status' => 'passed',
            'revision' => 3,
        ]);
    }

    private function fakeSequence(array $steps): void
    {
        $sequence = Http::sequence();
        foreach ($steps as $content) {
            $sequence->push(['success' => true, 'result' => ['id' => 'rec-1', 'content' => $content]], 200);
        }
        Http::fake([self::RECORD_URL => $sequence]);
    }

    public function test_cutover_updates_record_binding_and_audit(): void
    {
        // GET old -> PATCH -> GET read-back
        $this->fakeSequence([self::OLD_IP, self::NEW_IP, self::NEW_IP]);

        $result = $this->dns->cutover($this->binding, self::NEW_IP, 3, 1);

        $this->assertFalse($result['idempotent']);
        $binding = $this->binding->fresh();
        $this->assertSame(self::NEW_IP, $binding->current_entry_ip);
        $this->assertSame(4, $binding->revision);

        $change = $result['change'];
        $this->assertSame('dns_cutover', $change->action);
        $this->assertSame(self::OLD_IP, $change->old_binding['current_entry_ip']);
        $this->assertSame(self::NEW_IP, $change->new_binding['current_entry_ip']);
        $this->assertTrue($change->postcheck_result['verified']);
        Http::assertSentCount(3);
    }

    public function test_cutover_is_idempotent_when_already_at_target(): void
    {
        Http::fake();
        $result = $this->dns->cutover($this->binding, self::OLD_IP, 3, 1);

        $this->assertTrue($result['idempotent']);
        $this->assertNull($result['change']);
        Http::assertNothingSent();
        $this->assertSame(0, SuzuEndpointChange::count());
    }

    public function test_cutover_rejects_stale_revision_before_any_api_call(): void
    {
        Http::fake();
        $this->expectException(SuzuEndpointException::class);
        try {
            $this->dns->cutover($this->binding, self::NEW_IP, 99, 1);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_cutover_requires_registered_record(): void
    {
        $this->binding->dns_record_id = null;
        $this->binding->save();
        Http::fake();
        $this->expectException(SuzuEndpointException::class);
        $this->dns->cutover($this->binding, self::NEW_IP, 3, 1);
    }

    public function test_cutover_requires_configured_token(): void
    {
        config()->set('suzu_endpoint.cloudflare.api_token', '');
        Http::fake();
        $this->expectException(SuzuEndpointException::class);
        $this->dns->cutover($this->binding, self::NEW_IP, 3, 1);
    }

    public function test_rollback_restores_old_ip_and_marks_original(): void
    {
        // Cutover: GET old -> PATCH -> GET new. Rollback: PATCH -> GET old.
        $this->fakeSequence([self::OLD_IP, self::NEW_IP, self::NEW_IP, self::OLD_IP, self::OLD_IP]);

        $cutover = $this->dns->cutover($this->binding, self::NEW_IP, 3, 1);
        $rolled = $this->dns->rollback($cutover['change'], 4, 1);

        $binding = $this->binding->fresh();
        $this->assertSame(self::OLD_IP, $binding->current_entry_ip);
        $this->assertSame(5, $binding->revision);
        $this->assertSame('rolled_back', $cutover['change']->fresh()->rollback_status);
        $this->assertSame('dns_rollback', $rolled['change']->action);
        $this->assertSame($cutover['change']->id, $rolled['change']->related_change_id);

        // A second rollback of the same cutover is refused.
        $this->expectException(SuzuEndpointException::class);
        $this->dns->rollback($cutover['change']->fresh(), 5, 1);
    }
}
