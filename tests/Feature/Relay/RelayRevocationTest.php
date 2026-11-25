<?php

namespace Tests\Feature\Relay;

use App\Models\RelayAuditLog;
use App\Models\RelayConfigOutbox;
use App\Models\RelayRevocation;
use App\Models\RelayRule;
use App\Models\ServerMachine;
use App\Services\Relay\RelayConfigService;
use App\Services\Relay\RelayReconcileService;
use App\Services\Relay\RelayRuleService;

/**
 * GPT incremental review round 2, item B01 (R03 completion): revocation
 * tracking must cover disable and delete, not only publish-time
 * exclusion, and ACK confirmation must be per rule against the ACKed
 * document — never a blanket clear.
 *
 * GPT's reproduced defect sequence: rule ACKed APPLIED -> agent goes
 * offline -> admin disables (or deletes) the rule -> publish excludes
 * it, but round 1 captured pending state by querying status=APPLIED
 * *after* the disable had already flipped the status (and after the
 * delete had removed the row), so no pending record existed anywhere;
 * the returning agent's ACK then cleared every pending marker for the
 * machine regardless of what its document actually contained.
 */
class RelayRevocationTest extends RelayTestCase
{
    private function config(): RelayConfigService
    {
        return app(RelayConfigService::class);
    }

    private function rules(): RelayRuleService
    {
        return app(RelayRuleService::class);
    }

    private function manualRuleOn(ServerMachine $relay, int $port, string $name): RelayRule
    {
        return $this->rules()->create($relay, $this->manualRule(['name' => $name, 'listen_port' => $port]));
    }

    private function ackLatest(ServerMachine $relay): void
    {
        $doc = $this->config()->latestForAgent($relay->fresh());
        $this->assertTrue($this->config()->handleAck($relay->fresh(), $doc['version'], $doc['hash'], true));
    }

    /**
     * The brief's required scenario, end to end: agent offline ->
     * disable one rule + delete another -> both obligations visible
     * mid-way -> agent reconnects -> a stale ACK confirms nothing ->
     * ACK of the excluding document confirms both, per rule.
     */
    public function test_disable_and_delete_while_agent_offline_are_tracked_and_confirmed_per_rule(): void
    {
        $relay = $this->enableRelay($this->makeMachine('R-B01'));
        $ruleA = $this->manualRuleOn($relay, 20211, '将被停用');
        $ruleB = $this->manualRuleOn($relay, 20212, '将被删除');
        $ruleC = $this->manualRuleOn($relay, 20213, '保持在线');

        // Agent online: ACKs the document containing all three rules.
        $this->ackLatest($relay);
        foreach ([$ruleA, $ruleB, $ruleC] as $rule) {
            $this->assertSame(RelayRule::STATUS_APPLIED, $rule->fresh()->status);
        }
        $this->assertSame(0, RelayRevocation::whereNull('confirmed_at')->count());
        $staleOutbox = RelayConfigOutbox::where('machine_id', $relay->id)
            ->orderByDesc('version')->first();
        $this->assertNotNull($staleOutbox);

        // Agent goes offline. The admin disables A and deletes B.
        $this->rules()->setEnabled($ruleA->fresh(), false);
        $this->rules()->delete($ruleB->fresh());

        // Both obligations exist and stay visible while the agent is
        // away — B's even though its rule row no longer exists.
        $open = RelayRevocation::whereNull('confirmed_at')->get()->keyBy('rule_code');
        $this->assertCount(2, $open, 'disable and delete must both leave a tracked obligation');
        $this->assertSame(RelayRevocation::REASON_DISABLED, $open[$ruleA->rule_code]->reason);
        $this->assertSame(RelayRevocation::REASON_DELETED, $open[$ruleB->rule_code]->reason);
        $this->assertGreaterThan(0, (int) $open[$ruleA->rule_code]->since_version);
        $this->assertGreaterThan(0, (int) $open[$ruleB->rule_code]->since_version);
        $this->assertNull(RelayRule::find($ruleB->id), 'the rule row is gone; the obligation must not be');
        $this->assertNotNull($ruleA->fresh()->revocation_pending_at);

        $summary = app(RelayReconcileService::class)->run();
        $this->assertSame(2, $summary['revocation_pending'], 'reconcile reports obligations, including the deleted rule');

        // A stale ACK of the old document (which still contains A and
        // B) confirms nothing — per-rule confirmation judges the ACKed
        // document's actual contents.
        $this->assertTrue($this->config()->handleAck(
            $relay->fresh(), (int) $staleOutbox->version, $staleOutbox->config_hash, true
        ));
        $this->assertSame(2, RelayRevocation::whereNull('confirmed_at')->count(),
            'an ACK whose document still contains the rules must not confirm their revocation');

        // The agent reconnects and ACKs the current document (only C):
        // both obligations are confirmed against that version.
        $latest = $this->config()->latestForAgent($relay->fresh());
        $this->assertTrue($this->config()->handleAck($relay->fresh(), $latest['version'], $latest['hash'], true));
        $this->assertSame(0, RelayRevocation::whereNull('confirmed_at')->count());
        foreach ([$ruleA, $ruleB] as $rule) {
            $obligation = RelayRevocation::where('rule_code', $rule->rule_code)->first();
            $this->assertNotNull($obligation->confirmed_at);
            $this->assertSame($latest['version'], (int) $obligation->confirmed_version);
        }
        $this->assertNull($ruleA->fresh()->revocation_pending_at);
        $this->assertSame(RelayRule::STATUS_APPLIED, $ruleC->fresh()->status);
        $this->assertTrue(RelayAuditLog::where('machine_id', $relay->id)
            ->where('action', 'rule.revocation_confirmed')->exists());
    }

    /** The third capture path: a rule excluded because it went BROKEN. */
    public function test_broken_exclusion_records_an_obligation(): void
    {
        $relay = $this->enableRelay($this->makeMachine('R-B01-BROKEN'));
        $landing = $this->makeMachine('L-B01-BROKEN');
        $node = $this->makeNode($landing, ['server_port' => 8444]);
        $rule = $this->rules()->create($relay, [
            'name' => 'NODE-BROKEN', 'transport' => 'tcp', 'listen_ip' => '0.0.0.0', 'listen_port' => 20214,
            'target_type' => 'NODE', 'target_node_id' => $node->id,
        ]);
        $this->ackLatest($relay);
        $this->assertSame(RelayRule::STATUS_APPLIED, $rule->fresh()->status);

        // The node loses eligibility; the next publish excludes the rule
        // as BROKEN — an obligation with reason=broken must be recorded.
        $node->forceFill(['enabled' => false])->save();
        $this->config()->publish($relay->fresh());

        $obligation = RelayRevocation::openFor($relay->id, $rule->rule_code);
        $this->assertNotNull($obligation, 'BROKEN exclusion must record a revocation obligation');
        $this->assertSame(RelayRevocation::REASON_BROKEN, $obligation->reason);

        $this->ackLatest($relay);
        $this->assertNull(RelayRevocation::openFor($relay->id, $rule->rule_code));
        $this->assertNotNull(RelayRevocation::where('rule_code', $rule->rule_code)->first()->confirmed_at);
    }

    /** Republishing a rule closes its obligation: the premise is gone. */
    public function test_republishing_a_rule_closes_its_obligation(): void
    {
        $relay = $this->enableRelay($this->makeMachine('R-B01-REPUB'));
        $rule = $this->manualRuleOn($relay, 20215, '停用后恢复');
        $this->ackLatest($relay);

        $this->rules()->setEnabled($rule->fresh(), false);
        $this->assertNotNull(RelayRevocation::openFor($relay->id, $rule->rule_code));

        $this->rules()->setEnabled($rule->fresh(), true);
        $obligation = RelayRevocation::where('rule_code', $rule->rule_code)->first();
        $this->assertNotNull($obligation->confirmed_at, 'republication must close the obligation');
        $this->assertStringContainsString('published again', (string) $obligation->confirm_note);
        $this->assertNull($rule->fresh()->revocation_pending_at);
    }

    /**
     * N01 (GPT round 3): GPT's acceptance sequence — ACK v_old, ACK
     * v_new, then a late duplicate ACK of v_old. Round 2 rewrote the
     * machine's applied version/hash from whichever ACK arrived last,
     * so the late ACK rolled the panel's view of the agent back to
     * v_old. The applied state must be monotonic.
     */
    public function test_stale_ack_never_rolls_back_applied_version(): void
    {
        $relay = $this->enableRelay($this->makeMachine('R-N01'));
        $this->manualRuleOn($relay, 20231, '甲线');
        $this->manualRuleOn($relay, 20232, '乙线');
        $config = $this->config();

        $latest = $config->latestForAgent($relay->fresh());
        $previous = RelayConfigOutbox::where('machine_id', $relay->id)
            ->where('version', $latest['version'] - 1)->first();
        $this->assertNotNull($previous);

        // The agent applies the older document, then the newer one.
        $this->assertTrue($config->handleAck($relay->fresh(), (int) $previous->version, $previous->config_hash, true));
        $this->assertSame((int) $previous->version, (int) $relay->fresh()->relay_applied_version);
        $this->assertTrue($config->handleAck($relay->fresh(), $latest['version'], $latest['hash'], true));
        $machine = $relay->fresh();
        $this->assertSame($latest['version'], (int) $machine->relay_applied_version);
        $this->assertSame($latest['hash'], $machine->relay_applied_hash);
        // Sentinel: if the late ACK rewrote the applied bookkeeping,
        // this timestamp would jump back to "now".
        $machine->forceFill(['relay_applied_at' => '2020-01-01 00:00:00'])->save();
        $sentinel = (int) $relay->fresh()->relay_applied_at;

        // The late ACK of the older version arrives.
        $this->assertTrue($config->handleAck($relay->fresh(), (int) $previous->version, $previous->config_hash, true));
        $machine = $relay->fresh();
        $this->assertSame($latest['version'], (int) $machine->relay_applied_version,
            'a stale ACK must not roll the applied version back');
        $this->assertSame($latest['hash'], $machine->relay_applied_hash,
            'a stale ACK must not roll the applied hash back');
        $this->assertSame($sentinel, (int) $machine->relay_applied_at,
            'a stale ACK must not rewrite the applied timestamp');
        $this->assertTrue(RelayAuditLog::where('machine_id', $relay->id)
            ->where('action', 'config.ack')
            ->where('detail', 'like', '%stale ack%')->exists(), 'the stale ACK is audit-recorded as stale');
    }

    /**
     * N01, the revocation half of the fix standard: refusing the stale
     * ACK's version rollback must not cancel legitimate per-document
     * revocation confirmation. A stale ACK whose document still
     * contains a rule confirms nothing about it; a stale ACK of an
     * excluding document still confirms the obligations it covers.
     */
    public function test_stale_ack_still_confirms_revocations_per_document(): void
    {
        $relay = $this->enableRelay($this->makeMachine('R-N01-REV'));
        $ruleA = $this->manualRuleOn($relay, 20241, '甲线');
        $ruleB = $this->manualRuleOn($relay, 20242, '乙线');
        $config = $this->config();
        $this->ackLatest($relay);
        $bothDoc = RelayConfigOutbox::where('machine_id', $relay->id)->orderByDesc('version')->first();

        // Disable B: the excluding document is published; the agent
        // ACKs it, so B's obligation confirms on the normal path.
        $this->rules()->setEnabled($ruleB->fresh(), false);
        $excludingDoc = RelayConfigOutbox::where('machine_id', $relay->id)->orderByDesc('version')->first();
        $this->assertGreaterThan((int) $bothDoc->version, (int) $excludingDoc->version);
        $this->assertTrue($config->handleAck($relay->fresh(), (int) $excludingDoc->version, $excludingDoc->config_hash, true));
        $obligationB = RelayRevocation::where('rule_code', $ruleB->rule_code)->first();
        $this->assertSame((int) $excludingDoc->version, (int) $obligationB->confirmed_version);

        // Disable A as well: a newer excluding document exists, its
        // obligation is open with since_version at that document.
        $this->rules()->setEnabled($ruleA->fresh(), false);
        $emptyDoc = RelayConfigOutbox::where('machine_id', $relay->id)->orderByDesc('version')->first();
        $obligationA = RelayRevocation::openFor($relay->id, $ruleA->rule_code);
        $this->assertNotNull($obligationA);
        $this->assertSame((int) $emptyDoc->version, (int) $obligationA->since_version);

        // A stale ACK of the {A,B} document: applied state must not
        // move, and it confirms nothing — its document still contains
        // A, and its version predates A's exclusion anyway.
        $this->assertTrue($config->handleAck($relay->fresh(), (int) $bothDoc->version, $bothDoc->config_hash, true));
        $this->assertSame((int) $excludingDoc->version, (int) $relay->fresh()->relay_applied_version);
        $this->assertNotNull(RelayRevocation::openFor($relay->id, $ruleA->rule_code),
            'a stale ACK must not confirm an obligation it does not cover');

        // The current document's ACK confirms A on the normal path.
        $this->assertTrue($config->handleAck($relay->fresh(), (int) $emptyDoc->version, $emptyDoc->config_hash, true));
        $this->assertNull(RelayRevocation::openFor($relay->id, $ruleA->rule_code));
        $this->assertSame((int) $emptyDoc->version, (int) $relay->fresh()->relay_applied_version);

        // The race N01 names: an obligation captured against the
        // excluding document whose ACK arrives only after a newer
        // version was applied. (Seeded directly: the service-layer
        // flows — republication closing, since_version supplement —
        // normally confirm such an obligation earlier; this isolates
        // handleAck's contract that a stale ACK still confirms what
        // its document legitimately covers.)
        $late = RelayRevocation::record($relay->id, $ruleB->rule_code, $ruleB->id,
            RelayRevocation::REASON_DISABLED, (int) $excludingDoc->version);
        $this->assertNull($late->confirmed_at);
        $this->assertTrue($config->handleAck($relay->fresh(), (int) $excludingDoc->version, $excludingDoc->config_hash, true));
        $late = $late->fresh();
        $this->assertNotNull($late->confirmed_at, 'a stale ACK of an excluding document still confirms its revocations');
        $this->assertSame((int) $excludingDoc->version, (int) $late->confirmed_version);
        $this->assertSame((int) $emptyDoc->version, (int) $relay->fresh()->relay_applied_version,
            '... while the applied version itself stays at the newer version');
    }
}
