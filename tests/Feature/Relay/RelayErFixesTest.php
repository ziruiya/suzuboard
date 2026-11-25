<?php

namespace Tests\Feature\Relay;

use App\Models\RelayAuditLog;
use App\Models\RelayRevocation;
use App\Models\RelayRule;
use App\Models\RelayTrafficBatch;
use App\Models\ServerMachine;
use App\Services\Relay\RelayConfigService;
use App\Services\Relay\RelayReconcileService;
use App\Services\Relay\RelayRuleService;
use App\Services\Relay\RelayTrafficService;
use App\Services\Relay\RelayValidationException;

/**
 * SB-ER batch-3 regression coverage (relay side):
 *  ER-012 a config rollback must not resurrect rules that are
 *         disabled NOW, must re-derive the machine-level document
 *         fields from current state, must refuse to run while the
 *         machine's relay is disabled, and must keep the normal
 *         publish bookkeeping (a rule the rollback drops leaves a
 *         revocation obligation, exactly like a normal publish);
 *  ER-013 when a rule's target node is deleted and the publish that
 *         should exclude it fails, the next reconcile round must
 *         still republish — the desired-vs-applied mismatch is the
 *         trigger, not a one-shot counter;
 *  ER-014 traffic ingest judges every batch independently and now
 *         reports WHICH batches were rejected and why (the panel
 *         enabler for the agent-side queue fix in batch 4).
 */
class RelayErFixesTest extends RelayTestCase
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
        $this->assertNotNull($doc);
        $this->assertTrue($this->config()->handleAck($relay->fresh(), $doc['version'], $doc['hash'], true));
    }

    private function latestRuleCodes(ServerMachine $relay): array
    {
        $doc = $this->config()->latestForAgent($relay->fresh());
        return collect($doc['config']['rules'] ?? [])->pluck('rule_code')->all();
    }

    private function latestVersion(ServerMachine $relay): int
    {
        return (int) $this->config()->latestForAgent($relay->fresh())['version'];
    }

    private function manualRuleRow(ServerMachine $relay, int $port): RelayRule
    {
        return $this->rules()->create($relay, $this->manualRule(['listen_port' => $port]));
    }

    // ------------------------------------------------------------- ER-012

    public function test_rollback_does_not_resurrect_a_currently_disabled_rule(): void
    {
        $relay = $this->enableRelay($this->makeMachine('R-ER12A'));
        $ruleA = $this->manualRuleOn($relay, 20221, '保持启用');
        $ruleB = $this->manualRuleOn($relay, 20222, '将被禁用');

        $this->config()->publish($relay->fresh());
        $this->ackLatest($relay);
        $this->assertSame(RelayRule::STATUS_APPLIED, $ruleB->fresh()->status);
        $bothVersion = $this->latestVersion($relay);

        $this->rules()->setEnabled($ruleB->fresh(), false);
        $this->config()->publish($relay->fresh());
        $this->ackLatest($relay);
        $this->assertSame([$ruleA->rule_code], $this->latestRuleCodes($relay));

        $this->config()->rollback($relay->fresh(), $bothVersion);

        $this->assertSame(
            [$ruleA->rule_code],
            $this->latestRuleCodes($relay),
            'ER-012: the republished historical document must exclude the rule disabled NOW'
        );
        $this->assertTrue(
            RelayAuditLog::where('machine_id', $relay->id)
                ->where('action', 'config.rollback_dropped_disabled')->exists(),
            'the drop is audited'
        );
        $b = $ruleB->fresh();
        $this->assertFalse((bool) $b->enabled);
        $this->assertSame(RelayRule::STATUS_DISABLED, $b->status, 'the disabled rule is not flipped back to an applying/applied state');
    }

    public function test_rollback_refused_while_relay_is_disabled_on_the_machine(): void
    {
        $relay = $this->enableRelay($this->makeMachine('R-ER12B'));
        $this->manualRuleOn($relay, 20224, '任意线');
        $this->config()->publish($relay->fresh());
        $this->ackLatest($relay);
        $version = $this->latestVersion($relay);

        $relay->forceFill(['relay_enabled' => false])->save();

        $this->expectException(RelayValidationException::class);
        $this->config()->rollback($relay->fresh(), $version);
    }

    public function test_rollback_keeps_revocation_bookkeeping_for_dropped_rules(): void
    {
        $relay = $this->enableRelay($this->makeMachine('R-ER12C'));
        $ruleA = $this->manualRuleOn($relay, 20225, '常驻线');
        $this->config()->publish($relay->fresh());
        $this->ackLatest($relay);
        $onlyAVersion = $this->latestVersion($relay);

        $ruleC = $this->manualRuleOn($relay, 20226, '后加线');
        $this->config()->publish($relay->fresh());
        $this->ackLatest($relay);
        $this->assertSame(RelayRule::STATUS_APPLIED, $ruleC->fresh()->status);

        $this->config()->rollback($relay->fresh(), $onlyAVersion);

        $this->assertSame([$ruleA->rule_code], $this->latestRuleCodes($relay));
        $this->assertTrue(
            RelayRevocation::where('machine_id', $relay->id)
                ->where('rule_code', $ruleC->rule_code)
                ->whereNull('confirmed_at')
                ->exists(),
            'ER-012: a rule dropped by a rollback leaves the same revocation obligation a normal publish would'
        );
    }

    // ------------------------------------------------------------- ER-013

    public function test_reconcile_republishes_after_target_deletion_and_a_failed_round(): void
    {
        $relay = $this->enableRelay($this->makeMachine('R-ER13'));
        $landing = $this->makeMachine('LANDING-ER13');
        $node = $this->makeNode($landing, ['host' => 'gone.example.test', 'server_port' => 443]);
        $rule = $this->rules()->create($relay->fresh(), [
            'name' => '目标将被删', 'transport' => 'tcp', 'listen_ip' => '0.0.0.0', 'listen_port' => 20227,
            'target_type' => 'NODE', 'target_node_id' => $node->id,
        ]);
        $this->config()->publish($relay->fresh());
        $this->ackLatest($relay);
        $versionBefore = $this->config()->latestForAgent($relay->fresh())['version'];

        // The target disappears; the publish round that should have
        // excluded the rule failed, leaving the rule BROKEN with the
        // agent still running the last applied document.
        $node->delete();
        $rule->forceFill(['status' => RelayRule::STATUS_BROKEN])->save();

        $summary = app(RelayReconcileService::class)->run();

        $this->assertGreaterThanOrEqual(1, $summary['republished'], 'ER-013: the mismatch must trigger a fresh publish');
        $doc = $this->config()->latestForAgent($relay->fresh());
        $this->assertGreaterThan($versionBefore, $doc['version']);
        $this->assertSame([], $this->latestRuleCodes($relay), 'the republished document no longer forwards to the dead target');
    }

    // ------------------------------------------------------------- ER-014

    public function test_ingest_reports_per_batch_rejections_and_keeps_good_batches(): void
    {
        $machine = $this->enableRelay($this->makeMachine('R-ER14'));
        $rule = $this->manualRuleRow($machine->fresh(), 20228);
        $start = (int) strtotime('2026-11-24 10:00:00 UTC');
        $good = [
            'rule_code' => $rule->rule_code, 'boot_id' => 'boot-1', 'seq' => 1,
            'period_start' => $start, 'period_end' => $start + 600,
            'bytes_in' => 1000, 'bytes_out' => 2000, 'connections' => 3,
        ];
        $unknown = array_merge($good, ['rule_code' => 'RL999999', 'seq' => 2]);
        $invalid = array_merge($good, ['seq' => 3, 'period_end' => $start - 1]);

        $result = app(RelayTrafficService::class)->ingest($machine->fresh(), [$good, $unknown, $invalid]);

        $this->assertSame(1, $result['accepted'], 'the good batch is booked even though its neighbours are bad');
        $this->assertSame(2, $result['rejected']);
        $this->assertSame(1, RelayTrafficBatch::count());
        $byCode = collect($result['rejected_batches'])->keyBy('rule_code');
        $this->assertSame('unknown_rule', $byCode['RL999999']['reason']);
        $this->assertSame('invalid_batch', $byCode[$rule->rule_code]['reason']);
        $this->assertSame(3, $byCode[$rule->rule_code]['seq']);
    }
}
