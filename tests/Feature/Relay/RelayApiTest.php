<?php

namespace Tests\Feature\Relay;

use App\Models\RelayRuleStat;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

/**
 * SB-RELAY-01 HTTP surface: admin API (Sanctum admin + confirm convention)
 * and the agent channel (machine token): config pull, ACK, traffic ingest.
 */
class RelayApiTest extends RelayTestCase
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
            'suzu/relay/machines', 'suzu/relay/machines/toggle', 'suzu/relay/machines/tier',
            'suzu/relay/nodes', 'suzu/relay/rules', 'suzu/relay/rules/update',
            'suzu/relay/rules/toggle', 'suzu/relay/rules/drop',
            'suzu/relay/outbox', 'suzu/relay/rollback', 'suzu/relay/republish', 'suzu/relay/audit',
            'server/machine/relay/config', 'server/machine/relay/ack', 'server/machine/relay/traffic',
        ] as $suffix) {
            $this->assertTrue(
                $uris->contains(fn($uri) => str_ends_with($uri, $suffix)),
                "route {$suffix} is not registered"
            );
        }
    }

    public function test_admin_flow_enable_create_and_list(): void
    {
        $admin = $this->makeUser(['is_admin' => 1, 'email' => 'admin@example.com']);
        Sanctum::actingAs($admin);
        $machine = $this->makeMachine('API-M1');
        $landing = $this->makeMachine('API-LANDING');
        $node = $this->makeNode($landing);

        // Writes without confirm=true are refused.
        $refused = $this->postJson($this->uriBySuffix('suzu/relay/machines/toggle'), [
            'machine_id' => $machine->id, 'action' => 'enable',
        ]);
        $refused->assertStatus(422);
        $this->assertFalse($machine->fresh()->relay_enabled);

        $enabled = $this->postJson($this->uriBySuffix('suzu/relay/machines/toggle'), [
            'machine_id' => $machine->id, 'action' => 'enable', 'confirm' => true,
        ]);
        $enabled->assertOk();
        $this->assertTrue($machine->fresh()->relay_enabled);

        $created = $this->postJson($this->uriBySuffix('suzu/relay/rules'), [
            'machine_id' => $machine->id, 'confirm' => true,
            'name' => 'API线', 'transport' => 'both', 'listen_ip' => '0.0.0.0', 'listen_port' => 20070,
            'target_type' => 'NODE', 'target_node_id' => $node->id,
        ]);
        $created->assertOk();
        $this->assertSame('RL000001', $created->json('data.rule_code'));
        $this->assertSame($node->host, $created->json('data.target_host'));

        $list = $this->getJson($this->uriBySuffix('suzu/relay/rules') . '?machine_id=' . $machine->id);
        $list->assertOk();
        $this->assertCount(1, $list->json('data'));

        // Port outside the pool is refused at the API layer too.
        $badPort = $this->postJson($this->uriBySuffix('suzu/relay/rules'), [
            'machine_id' => $machine->id, 'confirm' => true,
            'name' => '坏端口', 'transport' => 'tcp', 'listen_ip' => '0.0.0.0', 'listen_port' => 443,
            'target_type' => 'NODE', 'target_node_id' => $node->id,
        ]);
        $badPort->assertStatus(422);

        $machines = $this->getJson($this->uriBySuffix('suzu/relay/machines'));
        $machines->assertOk();
        $row = collect($machines->json('data'))->firstWhere('id', $machine->id);
        $this->assertSame(1, $row['rule_count']);
        $this->assertSame('ENABLED', $row['relay_status']);
    }

    public function test_agent_channel_config_ack_traffic(): void
    {
        $machine = $this->enableRelay($this->makeMachine('AGENT-M1'));
        $rule = app(\App\Services\Relay\RelayRuleService::class)->create($machine->fresh(), $this->manualRule([
            'listen_port' => 20071,
        ]));
        $machine = $machine->fresh();

        // Bad token is refused.
        $denied = $this->postJson($this->uriBySuffix('server/machine/relay/config'), [
            'machine_id' => $machine->id, 'token' => 'wrong',
        ]);
        $denied->assertStatus(403);

        $pulled = $this->postJson($this->uriBySuffix('server/machine/relay/config'), [
            'machine_id' => $machine->id, 'token' => $machine->token,
        ]);
        $pulled->assertOk();
        $this->assertSame('relay.config.v1', $pulled->json('protocol'));
        $version = $pulled->json('version');
        $hash = $pulled->json('hash');
        $this->assertSame($rule->rule_code, $pulled->json('config.rules.0.rule_code'));

        $ack = $this->postJson($this->uriBySuffix('server/machine/relay/ack'), [
            'machine_id' => $machine->id, 'token' => $machine->token,
            'version' => $version, 'hash' => $hash, 'applied' => true,
        ]);
        $ack->assertOk();
        $this->assertSame((int) $version, (int) $machine->fresh()->relay_applied_version);

        $start = (int) strtotime('2026-11-24 08:00:00 UTC');
        $payload = [
            'machine_id' => $machine->id, 'token' => $machine->token,
            'batches' => [[
                'rule_code' => $rule->rule_code, 'boot_id' => 'b1', 'seq' => 1,
                'period_start' => $start, 'period_end' => $start + 300,
                'bytes_in' => 500, 'bytes_out' => 700,
            ]],
        ];
        $first = $this->postJson($this->uriBySuffix('server/machine/relay/traffic'), $payload);
        $first->assertOk();
        $this->assertSame(1, $first->json('data.accepted'));
        $replay = $this->postJson($this->uriBySuffix('server/machine/relay/traffic'), $payload);
        $replay->assertOk();
        $this->assertSame(1, $replay->json('data.duplicates'));
        $stat = RelayRuleStat::where('rule_id', $rule->id)->where('record_type', 'd')->first();
        $this->assertSame(500, (int) $stat->u);
        $this->assertSame(700, (int) $stat->d);
    }
}
