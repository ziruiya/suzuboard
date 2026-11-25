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

        // R02 note: setPublicAddress now prechecks the new address by TCP
        // probe from the panel host. 198.51.100.77 is TEST-NET (never
        // reachable), so this republish-following test injects a passing
        // prober; the refusal path has its own test in MachineRoleTest.
        (new MachineRoleService(app(RelayConfigService::class), fn() => true))
            ->setPublicAddress($landing->fresh(), '198.51.100.77');

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

    /**
     * GPT review R07: direct_port=0 is corrupt data, not "unset". The old
     * falsy ?: silently fell back to server_port; strict null semantics
     * must surface it as an invalid endpoint, and the write-side form
     * rules must reject 0 outright.
     */
    public function test_direct_port_zero_is_rejected_not_treated_as_unset(): void
    {
        $landing = $this->makeMachine('L-R07');
        $node = $this->makeNode($landing, ['server_port' => 8444]);
        \Illuminate\Support\Facades\DB::table('v2_server')->where('id', $node->id)
            ->update(['direct_port' => 0]);

        [$endpoint, $error] = RelayRuleService::nodeLandingEndpoint($node->fresh());
        $this->assertNull($endpoint, 'direct_port=0 must not fall back to server_port');
        $this->assertStringContainsString('no valid landing port', (string) $error);

        $request = \App\Http\Requests\Admin\ServerSave::create('/admin/server/save', 'POST', ['type' => 'vless']);
        $rules = $request->rules();
        $this->assertArrayHasKey('direct_port', $rules);
        $validator = \Illuminate\Support\Facades\Validator::make(['direct_port' => 0], ['direct_port' => $rules['direct_port']]);
        $this->assertTrue($validator->fails(), 'direct_port=0 must fail write validation');
        $validator = \Illuminate\Support\Facades\Validator::make(['direct_port' => 8444], ['direct_port' => $rules['direct_port']]);
        $this->assertFalse($validator->fails());
        $validator = \Illuminate\Support\Facades\Validator::make(['direct_port' => null], ['direct_port' => $rules['direct_port']]);
        $this->assertFalse($validator->fails());
    }

    /**
     * GPT review R03: a rule the agent had ACKed as APPLIED and that a
     * later publish excludes stays "revocation pending" until the agent
     * ACKs a document without it — an offline agent must not be assumed
     * to have stopped serving it.
     */
    public function test_excluded_applied_rule_is_revocation_pending_until_agent_acks(): void
    {
        $relay = $this->enableRelay($this->makeMachine('R-R03'));
        $landing = $this->makeMachine('L-R03');
        $node = $this->makeNode($landing, ['server_port' => 8444]);
        $rule = $this->nodeRule($relay, $node->id, 20121);
        $config = app(RelayConfigService::class);

        $v1 = $config->latestForAgent($relay->fresh());
        $this->assertTrue($config->handleAck($relay->fresh(), $v1['version'], $v1['hash'], true));
        $this->assertSame(RelayRule::STATUS_APPLIED, $rule->fresh()->status);
        $this->assertNull($rule->fresh()->revocation_pending_at);

        // The node loses eligibility; the next publish excludes the rule.
        $node->forceFill(['enabled' => false])->save();
        $config->publish($relay->fresh());
        $v2 = $config->latestForAgent($relay->fresh());
        $this->assertCount(0, $v2['config']['rules']);

        $rule = $rule->fresh();
        $this->assertNotNull($rule->revocation_pending_at,
            'exclusion of an APPLIED rule must stay visibly pending until the agent confirms');
        $this->assertTrue(\App\Models\RelayAuditLog::where('machine_id', $relay->id)
            ->where('action', 'rule.revocation_pending')->exists());

        $summary = app(\App\Services\Relay\RelayReconcileService::class)->run();
        $this->assertGreaterThanOrEqual(1, $summary['revocation_pending']);

        // The agent comes back and ACKs the latest document (reconcile
        // may have republished again on eligibility hash drift — the ACK
        // that counts is for the newest version, which also excludes the
        // rule): only now is the revocation confirmed.
        $latest = $config->latestForAgent($relay->fresh());
        $this->assertTrue($config->handleAck($relay->fresh(), $latest['version'], $latest['hash'], true));
        $this->assertNull($rule->fresh()->revocation_pending_at);
        $this->assertTrue(\App\Models\RelayAuditLog::where('machine_id', $relay->id)
            ->where('action', 'rule.revocation_confirmed')->exists());
    }

    /**
     * GPT review R08: the contract hash covers eligibility-derived state.
     * Adding a production-rollout Suzu profile changes neither the
     * endpoint nor the deliverable verdict — yet the hash must move, the
     * reconcile loop must republish, and the ACK must realign the rule.
     */
    public function test_rollout_state_change_moves_hash_and_reconcile_republishes(): void
    {
        $relay = $this->enableRelay($this->makeMachine('R-R08'));
        $landing = $this->makeMachine('L-R08');
        $node = $this->makeNode($landing, ['server_port' => 8444]);
        $rule = $this->nodeRule($relay, $node->id, 20122);
        $config = app(RelayConfigService::class);

        $hashBefore = RelayRuleService::nodeContractHash($node->fresh());
        $this->assertSame($hashBefore, $rule->fresh()->target_contract_hash);
        $v1 = $config->latestForAgent($relay->fresh());
        $this->assertTrue($config->handleAck($relay->fresh(), $v1['version'], $v1['hash'], true));

        \App\Models\SuzuNodeProfile::create([
            'server_id' => $node->id,
            'profile_code' => 'sw1',
            'schema_version' => 1,
            'relay_policy' => 'required',
            'rollout_stage' => 'production',
            'client_policy' => 'standard',
            'udp_enabled' => false,
        ]);

        $hashAfter = RelayRuleService::nodeContractHash($node->fresh());
        $this->assertNotSame($hashBefore, $hashAfter,
            'a rollout-stage change must move the contract hash even when endpoint and verdict are unchanged');

        $summary = app(\App\Services\Relay\RelayReconcileService::class)->run();
        $this->assertGreaterThanOrEqual(1, $summary['republished']);
        $v2 = $config->latestForAgent($relay->fresh());
        $this->assertSame($v1['version'] + 1, $v2['version']);
        $this->assertSame($hashAfter, $rule->fresh()->target_contract_hash);

        $this->assertTrue($config->handleAck($relay->fresh(), $v2['version'], $v2['hash'], true));
        $this->assertSame($v2['version'], (int) $rule->fresh()->config_version);
        $this->assertSame(RelayRule::STATUS_APPLIED, $rule->fresh()->status);
    }

    /**
     * GPT review R12: rollback re-derives NODE endpoints from CURRENT
     * state. The node's landing port moved after v1 — rolling back to v1
     * must republish the rule with today's endpoint, not resurrect v1's.
     */
    public function test_rollback_rederives_current_endpoint(): void
    {
        $relay = $this->enableRelay($this->makeMachine('R-R12'));
        $landing = $this->makeMachine('L-R12');
        $node = $this->makeNode($landing, ['server_port' => 8444]);
        $this->nodeRule($relay, $node->id, 20123);
        $config = app(RelayConfigService::class);
        $v1 = $config->latestForAgent($relay->fresh());
        $this->assertSame(8444, $v1['config']['rules'][0]['target_port']);

        $node->forceFill(['server_port' => 9444])->save();
        $config->publish($relay->fresh());

        $rolled = $config->rollback($relay->fresh(), $v1['version']);
        $payload = json_decode($rolled->payload, true);
        $this->assertSame(9444, (int) $payload['rules'][0]['target_port'],
            'rollback must re-derive the endpoint from current state, not republish the historical one');
        $this->assertSame($v1['version'], (int) $payload['rolled_back_from']);
    }

    /** GPT review R12: a historical endpoint that no longer resolves refuses the rollback. */
    public function test_rollback_refused_when_node_no_longer_deliverable(): void
    {
        $relay = $this->enableRelay($this->makeMachine('R-R12B'));
        $landing = $this->makeMachine('L-R12B');
        $node = $this->makeNode($landing, ['server_port' => 8444]);
        $this->nodeRule($relay, $node->id, 20124);
        $config = app(RelayConfigService::class);
        $v1 = $config->latestForAgent($relay->fresh());

        $node->forceFill(['enabled' => false])->save();
        $outboxCount = \App\Models\RelayConfigOutbox::where('machine_id', $relay->id)->count();
        try {
            $config->rollback($relay->fresh(), $v1['version']);
            $this->fail('rollback must be refused when the historical target lost eligibility');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('rollback refused', $e->getMessage());
        }
        $this->assertSame($outboxCount, \App\Models\RelayConfigOutbox::where('machine_id', $relay->id)->count(),
            'a refused rollback must not create a new outbox version');
    }
}
