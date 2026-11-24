<?php

namespace Tests\Feature\Relay;

use App\Models\EnterpriseInventoryNode;
use App\Models\RelayPortClaim;
use App\Models\RelayRule;
use App\Services\Relay\RelayRuleService;
use App\Services\Relay\RelayValidationException;

/**
 * SB-RELAY-01 rule guards: port pool, port claims, target eligibility,
 * allowlist, loop prevention, per-machine caps.
 */
class RelayRuleServiceTest extends RelayTestCase
{
    private function rules(): RelayRuleService
    {
        return app(RelayRuleService::class);
    }

    public function test_port_pool_boundaries(): void
    {
        $machine = $this->enableRelay($this->makeMachine('M1'));
        foreach ([19999, 29000, 8443, 0] as $bad) {
            try {
                $this->rules()->create($machine, $this->manualRule(['listen_port' => $bad, 'name' => 'bad' . $bad]));
                $this->fail('port ' . $bad . ' should be rejected');
            } catch (RelayValidationException $e) {
                $this->assertStringContainsString('pool', $e->getMessage());
            }
        }
        $lo = $this->rules()->create($machine, $this->manualRule(['listen_port' => 20000, 'name' => 'lo']));
        $hi = $this->rules()->create($machine, $this->manualRule(['listen_port' => 28999, 'name' => 'hi']));
        $this->assertSame(20000, (int) $lo->listen_port);
        $this->assertSame(28999, (int) $hi->listen_port);
        $this->assertMatchesRegularExpression('/^RL\d{6}$/', $lo->rule_code);
    }

    public function test_both_transport_expands_to_two_claims_and_conflict_is_rejected(): void
    {
        $machine = $this->enableRelay($this->makeMachine('M1'));
        $rule = $this->rules()->create($machine, $this->manualRule(['transport' => 'both', 'listen_port' => 20010]));
        $claims = RelayPortClaim::where('rule_id', $rule->id)->orderBy('transport')->get();
        $this->assertCount(2, $claims);
        $this->assertSame(['tcp', 'udp'], $claims->pluck('transport')->all());

        // A second rule claiming the same ip/port for tcp must be refused.
        try {
            $this->rules()->create($machine, $this->manualRule(['transport' => 'tcp', 'listen_port' => 20010, 'name' => 'clash']));
            $this->fail('conflicting claim should be rejected');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('already claimed', $e->getMessage());
        }

        // UDP-only on the same port still conflicts with the BOTH rule's udp claim.
        try {
            $this->rules()->create($machine, $this->manualRule(['transport' => 'udp', 'listen_port' => 20010, 'name' => 'clash2']));
            $this->fail('udp claim should conflict with the both rule');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('already claimed', $e->getMessage());
        }

        // Same port on a different bind IP is a distinct claim and passes.
        $v6 = $this->rules()->create($machine, $this->manualRule([
            'transport' => 'tcp', 'listen_port' => 20010, 'listen_ip' => '::1',
            'target_host' => '127.0.0.1', 'name' => 'loopback-twin',
            'allowlist_cidrs' => null, 'allowlist_approved' => false,
        ]));
        $this->assertNotNull($v6->id);
    }

    public function test_manual_public_bind_requires_approved_allowlist(): void
    {
        $machine = $this->enableRelay($this->makeMachine('M1'));
        try {
            $this->rules()->create($machine, $this->manualRule(['allowlist_cidrs' => null, 'allowlist_approved' => false]));
            $this->fail('public manual target without allowlist should be rejected');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('allowlist', $e->getMessage());
        }
        try {
            $this->rules()->create($machine, $this->manualRule(['allowlist_approved' => false]));
            $this->fail('unapproved allowlist should be rejected');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('approved', $e->getMessage());
        }
        // Loopback bind needs no allowlist.
        $ok = $this->rules()->create($machine, $this->manualRule([
            'listen_ip' => '127.0.0.1', 'allowlist_cidrs' => null, 'allowlist_approved' => false,
        ]));
        $this->assertNotNull($ok->id);
    }

    public function test_node_target_must_be_agent_deliverable(): void
    {
        $machine = $this->enableRelay($this->makeMachine('M1'));
        $landing = $this->makeMachine('LANDING');

        $good = $this->makeNode($landing);
        $rule = $this->rules()->create($machine, [
            'name' => '绑节点', 'transport' => 'tcp', 'listen_ip' => '0.0.0.0', 'listen_port' => 20020,
            'target_type' => 'NODE', 'target_node_id' => $good->id,
        ]);
        $this->assertSame($good->host, $rule->target_host);
        $this->assertSame(443, (int) $rule->target_port);

        $disabled = $this->makeNode($landing, ['enabled' => false]);
        try {
            $this->rules()->create($machine, [
                'name' => '绑禁用节点', 'transport' => 'tcp', 'listen_ip' => '0.0.0.0', 'listen_port' => 20021,
                'target_type' => 'NODE', 'target_node_id' => $disabled->id,
            ]);
            $this->fail('disabled node target should be rejected');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('not agent-deliverable', $e->getMessage());
        }

        // Hidden enterprise stock node (show=false + inventory row): rejected.
        $stock = $this->makeNode($landing, ['show' => false]);
        EnterpriseInventoryNode::create([
            'kit_id' => 999, 'server_id' => $stock->id, 'node_role' => 'primary', 'port' => 8443,
        ]);
        try {
            $this->rules()->create($machine, [
                'name' => '绑库存节点', 'transport' => 'tcp', 'listen_ip' => '0.0.0.0', 'listen_port' => 20022,
                'target_type' => 'NODE', 'target_node_id' => $stock->id,
            ]);
            $this->fail('stock node target should be rejected');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('not agent-deliverable', $e->getMessage());
        }
    }

    public function test_direct_and_chain_loops_are_rejected(): void
    {
        $machine = $this->enableRelay($this->makeMachine('M1'));
        // Self-loop: target is the rule's own listen endpoint.
        try {
            $this->rules()->create($machine, $this->manualRule([
                'listen_port' => 20030, 'target_host' => '127.0.0.1', 'target_port' => 20030,
            ]));
            $this->fail('self loop should be rejected');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('loop', $e->getMessage());
        }
        // Chain: A -> B's port, then B -> A's port closes the loop.
        $this->rules()->create($machine, $this->manualRule([
            'name' => 'A', 'listen_port' => 20031, 'target_host' => '127.0.0.1', 'target_port' => 20032,
        ]));
        try {
            $this->rules()->create($machine, $this->manualRule([
                'name' => 'B', 'listen_port' => 20032, 'target_host' => '127.0.0.1', 'target_port' => 20031,
            ]));
            $this->fail('chain loop should be rejected');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('loop', $e->getMessage());
        }
    }

    public function test_cross_machine_cycle_is_rejected(): void
    {
        $a = $this->enableRelay($this->makeMachine('A'));
        $b = $this->enableRelay($this->makeMachine('B'));
        $nodeOnB = $this->makeNode($b);
        $nodeOnA = $this->makeNode($a);

        $this->rules()->create($a, [
            'name' => 'A->B', 'transport' => 'tcp', 'listen_ip' => '0.0.0.0', 'listen_port' => 20040,
            'target_type' => 'NODE', 'target_node_id' => $nodeOnB->id,
        ]);
        try {
            $this->rules()->create($b, [
                'name' => 'B->A', 'transport' => 'tcp', 'listen_ip' => '0.0.0.0', 'listen_port' => 20041,
                'target_type' => 'NODE', 'target_node_id' => $nodeOnA->id,
            ]);
            $this->fail('cross-machine cycle should be rejected');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('loop', $e->getMessage());
        }
    }

    public function test_machine_rule_cap_and_tier_defaults(): void
    {
        $small = $this->makeMachine('S');
        $this->assertSame(30, $small->relayRulesLimit());
        $this->assertSame(500, $small->relayTcpLimit());
        $this->assertSame(512, $small->relayUdpLimit());
        $large = $this->makeMachine('L', 'large');
        $this->assertSame(60, $large->relayRulesLimit());
        $this->assertSame(1000, $large->relayTcpLimit());

        $machine = $this->enableRelay($this->makeMachine('CAPPED'));
        $machine->forceFill(['relay_rules_limit' => 2])->save();
        $machine = $machine->fresh();
        $this->rules()->create($machine, $this->manualRule(['listen_port' => 20050, 'name' => 'one']));
        $this->rules()->create($machine, $this->manualRule(['listen_port' => 20051, 'name' => 'two']));
        try {
            $this->rules()->create($machine, $this->manualRule(['listen_port' => 20052, 'name' => 'three']));
            $this->fail('third rule should exceed the cap');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('limit reached', $e->getMessage());
        }
    }

    public function test_rule_creation_requires_active_relay_machine(): void
    {
        $machine = $this->makeMachine('OFF');
        try {
            $this->rules()->create($machine, $this->manualRule());
            $this->fail('non-relay machine should not take rules');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('not an active relay machine', $e->getMessage());
        }
        $this->assertSame(0, RelayRule::count());
    }
}
