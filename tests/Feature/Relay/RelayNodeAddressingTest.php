<?php

namespace Tests\Feature\Relay;

use App\Models\RelayRule;
use App\Models\ServerMachine;
use App\Services\Relay\MachineRoleService;
use App\Services\Relay\RelayConfigService;
use App\Services\Relay\RelayRuleService;
use App\Services\Relay\RelayValidationException;

/**
 * SB-RELAY NODE-target landing addressing (fix 2026-11-25):
 * a NODE rule's published target is the target node's LANDING endpoint —
 * hosting machine's public_address + effective landing port
 * (direct_port ?? server_port) — never the node's subscription host
 * (an entry alias) and never a front-proxied node's loopback backend
 * port. Regression coverage for the defect that blocked the VIP->DMIT
 * migration (rules dialed entry aliases and refused).
 */
class RelayNodeAddressingTest extends RelayTestCase
{
    private function rules(): RelayRuleService
    {
        return app(RelayRuleService::class);
    }

    private function nodeRule(ServerMachine $relay, int $nodeId, int $listenPort = 20101): RelayRule
    {
        return $this->rules()->create($relay, [
            'name' => '寻址线', 'transport' => 'tcp', 'listen_ip' => '0.0.0.0', 'listen_port' => $listenPort,
            'target_type' => 'NODE', 'target_node_id' => $nodeId,
        ]);
    }

    public function test_published_target_is_machine_address_plus_server_port_not_node_host(): void
    {
        $relay = $this->enableRelay($this->makeMachine('R1'));
        $landing = $this->makeMachine('L1');
        $node = $this->makeNode($landing, [
            'host' => 'entry-alias.example.test', 'port' => '8444', 'server_port' => 8444,
        ]);

        $rule = $this->nodeRule($relay, $node->id);
        $this->assertSame($landing->fresh()->public_address, $rule->target_host);
        $this->assertSame(8444, (int) $rule->target_port);

        $doc = app(RelayConfigService::class)->latestForAgent($relay->fresh());
        $this->assertSame($landing->fresh()->public_address, $doc['config']['rules'][0]['target_host']);
        $this->assertSame(8444, $doc['config']['rules'][0]['target_port']);
    }

    public function test_direct_port_overrides_server_port_for_front_proxied_nodes(): void
    {
        $relay = $this->enableRelay($this->makeMachine('R1'));
        $landing = $this->makeMachine('L1');
        // Node-27 shape: nginx on the landing machine listens on 8444,
        // the agent's real listener is a loopback-only 19444.
        $node = $this->makeNode($landing, [
            'host' => 'vip-alias.example.test', 'port' => '8444',
            'server_port' => 19444, 'direct_port' => 8444,
        ]);

        $rule = $this->nodeRule($relay, $node->id);
        $this->assertSame($landing->fresh()->public_address, $rule->target_host);
        $this->assertSame(8444, (int) $rule->target_port);
    }

    public function test_missing_public_address_rejected_at_create_and_breaks_at_publish(): void
    {
        $relay = $this->enableRelay($this->makeMachine('R1'));
        $landing = $this->makeMachine('L1');
        $node = $this->makeNode($landing);

        // Address cleared before creation: create must refuse.
        $landing->forceFill(['public_address' => null])->save();
        try {
            $this->nodeRule($relay, $node->id);
            $this->fail('missing public_address should be rejected');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('public_address', $e->getMessage());
        }

        // Address present at creation, cleared afterwards: the next
        // publish turns the rule BROKEN and excludes it.
        $landing->forceFill(['public_address' => '203.0.113.50'])->save();
        $rule = $this->nodeRule($relay, $node->id);
        $landing->forceFill(['public_address' => null])->save();
        app(RelayConfigService::class)->publish($relay->fresh());
        $this->assertSame(RelayRule::STATUS_BROKEN, $rule->fresh()->status);
        $doc = app(RelayConfigService::class)->latestForAgent($relay->fresh());
        $this->assertCount(0, $doc['config']['rules']);
    }

    public function test_node_without_machine_is_rejected(): void
    {
        $relay = $this->enableRelay($this->makeMachine('R1'));
        $orphan = $this->makeNode($this->makeMachine('TMP'), ['machine_id' => null]);
        try {
            $this->nodeRule($relay, $orphan->id);
            $this->fail('machine-less node target should be rejected');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('not hosted on any machine', $e->getMessage());
        }
    }

    public function test_node_on_the_relay_machine_itself_is_rejected(): void
    {
        $relay = $this->enableRelay($this->makeMachine('R1'));
        $own = $this->makeNode($relay);
        try {
            $this->nodeRule($relay, $own->id);
            $this->fail('same-machine node target should be rejected');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('roles are exclusive', $e->getMessage());
        }
    }

    public function test_resolve_mode_is_narrowed_to_auto(): void
    {
        $relay = $this->enableRelay($this->makeMachine('R1'));
        try {
            $this->rules()->create($relay, $this->manualRule([
                'listen_port' => 20102, 'target_resolve_mode' => 'pinned',
            ]));
            $this->fail('non-auto resolve mode should be rejected');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('target_resolve_mode', $e->getMessage());
        }
        $ok = $this->rules()->create($relay, $this->manualRule(['listen_port' => 20103]));
        $this->assertSame('auto', $ok->target_resolve_mode);
    }

    public function test_public_address_change_republishes_follower_relay_machines(): void
    {
        $relay = $this->enableRelay($this->makeMachine('R1'));
        $landing = $this->makeMachine('L1');
        $node = $this->makeNode($landing, ['server_port' => 8444]);
        $this->nodeRule($relay, $node->id);
        $before = app(RelayConfigService::class)->latestForAgent($relay->fresh());

        app(MachineRoleService::class)->setPublicAddress($landing->fresh(), '198.51.100.77');

        $after = app(RelayConfigService::class)->latestForAgent($relay->fresh());
        $this->assertGreaterThan($before['version'], $after['version']);
        $this->assertSame('198.51.100.77', $after['config']['rules'][0]['target_host']);
    }

    public function test_landing_role_machine_publishes_no_rules_even_with_relay_flag_set(): void
    {
        // Legacy state (HK2 after the pilot): relay_enabled=1 at the data
        // level but the machine hosts nodes. Once its role is landing,
        // the published document must carry no rules and relay off.
        $relay = $this->enableRelay($this->makeMachine('R1'));
        $landing = $this->makeMachine('L1');
        $node = $this->makeNode($landing);
        $this->nodeRule($relay, $node->id);

        $relay->forceFill(['role' => ServerMachine::ROLE_LANDING])->save();
        app(RelayConfigService::class)->publish($relay->fresh());
        $doc = app(RelayConfigService::class)->latestForAgent($relay->fresh());
        $this->assertFalse($doc['config']['relay_enabled']);
        $this->assertCount(0, $doc['config']['rules']);
    }

    public function test_landing_port_change_is_followed_on_next_publish(): void
    {
        // The batch-3 rotation act: listen port re-claimed on the relay
        // (rule update) + server_port moved on the node. The published
        // target must follow server_port with no stale window.
        $relay = $this->enableRelay($this->makeMachine('R1'));
        $landing = $this->makeMachine('L1');
        $node = $this->makeNode($landing, ['server_port' => 8444]);
        $rule = $this->nodeRule($relay, $node->id);

        $this->rules()->update($rule->fresh(), ['listen_port' => 20109]);
        $node->forceFill(['server_port' => 20999, 'port' => '20109'])->save();
        app(RelayConfigService::class)->publish($relay->fresh());

        $doc = app(RelayConfigService::class)->latestForAgent($relay->fresh());
        $this->assertSame(20109, $doc['config']['rules'][0]['listen_port']);
        $this->assertSame(20999, $doc['config']['rules'][0]['target_port']);
        $this->assertSame($landing->fresh()->public_address, $doc['config']['rules'][0]['target_host']);
    }
}
