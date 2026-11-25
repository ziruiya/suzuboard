<?php

namespace Tests\Feature\Relay;

use App\Models\RelayConfigOutbox;
use App\Models\RelayRule;
use App\Models\RelayRuleStat;
use App\Models\RelayTrafficBatch;
use App\Models\ServerMachine;
use App\Services\Relay\RelayConfigService;
use App\Services\Relay\RelayMachineService;
use App\Services\Relay\RelayReconcileService;
use App\Services\Relay\RelayRuleService;
use App\Services\Relay\RelayTrafficService;

/**
 * SB-RELAY-01 sync channel + traffic ledger + drain lifecycle:
 * outbox versioning, rollback-as-new-version, persistent ACK, NODE target
 * following, BROKEN on target loss, batch replay idempotency, UTC splits,
 * DRAINING -> DISABLED by ACK and by timeout.
 */
class RelaySyncTest extends RelayTestCase
{
    private function makeRule($machine, array $overrides = []): RelayRule
    {
        return app(RelayRuleService::class)->create($machine, $this->manualRule($overrides));
    }

    public function test_publish_versions_and_ack_flow(): void
    {
        $machine = $this->enableRelay($this->makeMachine('M1'));
        // Enabling published v1 (empty ruleset).
        $this->assertSame(1, (int) $machine->fresh()->relay_config_version);

        $rule = $this->makeRule($machine->fresh(), ['listen_port' => 20060]);
        $machine = $machine->fresh();
        $this->assertSame(2, (int) $machine->relay_config_version);
        $this->assertSame(RelayRule::STATUS_APPLYING, $rule->fresh()->status);

        $config = app(RelayConfigService::class);
        $latest = $config->latestForAgent($machine);
        $this->assertSame(2, $latest['version']);
        $this->assertSame('relay.config.v1', $latest['config']['protocol']);
        $this->assertCount(1, $latest['config']['rules']);
        $this->assertSame($rule->rule_code, $latest['config']['rules'][0]['rule_code']);

        // Wrong hash ACK is refused; correct ACK applies.
        $this->assertFalse($config->handleAck($machine, 2, 'deadbeef', true));
        $this->assertTrue($config->handleAck($machine, 2, $latest['hash'], true));
        $machine = $machine->fresh();
        $this->assertSame(2, (int) $machine->relay_applied_version);
        $this->assertSame(RelayRule::STATUS_APPLIED, $rule->fresh()->status);
        $this->assertSame(RelayConfigOutbox::STATUS_ACKED,
            RelayConfigOutbox::where('machine_id', $machine->id)->where('version', 2)->first()->status);
        // The superseded v1 stays on record.
        $this->assertSame(RelayConfigOutbox::STATUS_SUPERSEDED,
            RelayConfigOutbox::where('machine_id', $machine->id)->where('version', 1)->first()->status);
    }

    public function test_rollback_republishes_old_content_as_new_version(): void
    {
        $machine = $this->enableRelay($this->makeMachine('M1'));
        $rule = $this->makeRule($machine->fresh(), ['listen_port' => 20061, 'name' => '第一条']);
        $v2 = app(RelayConfigService::class)->latestForAgent($machine->fresh());
        $this->assertSame(2, $v2['version']);

        // Change the rule so v3 differs from v2. (Pre-R12 this test used
        // a DELETE as the change; GPT review R12 deliberately narrows
        // rollback: it restores a prior configuration of rules that
        // still exist — it is not an undelete. Republishing a rule the
        // panel no longer tracks would serve an orphan with no status,
        // hash or audit row, so rollback now refuses that case; see
        // RelayNodeAddressingTest::test_rollback_refused_when_node_no_longer_deliverable
        // and the design doc's GPT-review disposition.)
        app(RelayRuleService::class)->update($rule->fresh(), ['name' => '改名后', 'listen_port' => 20063]);
        $v3 = app(RelayConfigService::class)->latestForAgent($machine->fresh());
        $this->assertSame(3, $v3['version']);
        $this->assertSame('改名后', $v3['config']['rules'][0]['name']);

        $outbox = app(RelayConfigService::class)->rollback($machine->fresh(), 2);
        $this->assertSame(4, (int) $outbox->version);
        $doc = json_decode($outbox->payload, true);
        $this->assertSame(2, $doc['rolled_back_from']);
        $this->assertCount(1, $doc['rules']);
        $this->assertSame('第一条', $doc['rules'][0]['name']);
        $this->assertSame(20061, (int) $doc['rules'][0]['listen_port']);
    }

    public function test_node_target_follows_node_and_breaks_when_node_deleted(): void
    {
        $machine = $this->enableRelay($this->makeMachine('M1'));
        $landing = $this->makeMachine('LANDING');
        $node = $this->makeNode($landing, ['host' => 'old.example.test', 'server_port' => 443]);
        $rule = app(RelayRuleService::class)->create($machine->fresh(), [
            'name' => '跟随线', 'transport' => 'tcp', 'listen_ip' => '0.0.0.0', 'listen_port' => 20062,
            'target_type' => 'NODE', 'target_node_id' => $node->id,
        ]);

        // The node's LANDING changes (its machine's public address and
        // the node's landing port): the next publish follows the derived
        // endpoint. The node's subscription host is an entry alias and
        // deliberately plays no role in the published target.
        $node->forceFill(['host' => 'new.example.test', 'server_port' => 8443])->save();
        $landing->forceFill(['public_address' => '203.0.113.99'])->save();
        app(RelayConfigService::class)->publish($machine->fresh());
        $latest = app(RelayConfigService::class)->latestForAgent($machine->fresh());
        $this->assertSame('203.0.113.99', $latest['config']['rules'][0]['target_host']);
        $this->assertSame(8443, $latest['config']['rules'][0]['target_port']);
        $this->assertSame('203.0.113.99', $rule->fresh()->target_snapshot_host);

        // Node deleted: rule turns BROKEN and leaves the document.
        $node->delete();
        app(RelayConfigService::class)->publish($machine->fresh());
        $this->assertSame(RelayRule::STATUS_BROKEN, $rule->fresh()->status);
        $latest = app(RelayConfigService::class)->latestForAgent($machine->fresh());
        $this->assertCount(0, $latest['config']['rules']);
    }

    public function test_traffic_replay_ten_times_counts_once(): void
    {
        $machine = $this->enableRelay($this->makeMachine('M1'));
        $rule = $this->makeRule($machine->fresh(), ['listen_port' => 20063]);
        $start = (int) strtotime('2026-11-24 10:00:00 UTC');
        $batch = [
            'rule_code' => $rule->rule_code, 'boot_id' => 'boot-1', 'seq' => 1,
            'period_start' => $start, 'period_end' => $start + 600,
            'bytes_in' => 1000, 'bytes_out' => 2000, 'connections' => 3,
        ];
        $traffic = app(RelayTrafficService::class);
        $first = $traffic->ingest($machine->fresh(), [$batch]);
        // SB-ER-014: the result gained the additive rejected_batches
        // detail field; the counting contract is unchanged.
        $this->assertSame(['accepted' => 1, 'duplicates' => 0, 'rejected' => 0, 'rejected_batches' => []], $first);
        for ($i = 0; $i < 9; $i++) {
            $again = $traffic->ingest($machine->fresh(), [$batch]);
            $this->assertSame(['accepted' => 0, 'duplicates' => 1, 'rejected' => 0, 'rejected_batches' => []], $again);
        }
        $this->assertSame(1, RelayTrafficBatch::count());
        $day = RelayRuleStat::where('rule_id', $rule->id)->where('record_type', 'd')
            ->where('record_at', (int) strtotime('2026-11-24 00:00:00 UTC'))->first();
        $this->assertSame(1000, (int) $day->u);
        $this->assertSame(2000, (int) $day->d);
        // Relay traffic never lands on user counters.
        $this->assertSame(0, (int) \App\Models\User::sum('u'));
    }

    public function test_traffic_splits_across_utc_day_boundary(): void
    {
        $machine = $this->enableRelay($this->makeMachine('M1'));
        $rule = $this->makeRule($machine->fresh(), ['listen_port' => 20064]);
        $start = (int) strtotime('2026-11-24 23:00:00 UTC');
        $end = (int) strtotime('2026-11-25 01:00:00 UTC'); // exactly half / half
        app(RelayTrafficService::class)->ingest($machine->fresh(), [[
            'rule_code' => $rule->rule_code, 'boot_id' => 'boot-9', 'seq' => 7,
            'period_start' => $start, 'period_end' => $end,
            'bytes_in' => 7200, 'bytes_out' => 3600,
        ]]);
        $d24 = RelayRuleStat::where('rule_id', $rule->id)->where('record_type', 'd')
            ->where('record_at', (int) strtotime('2026-11-24 00:00:00 UTC'))->first();
        $d25 = RelayRuleStat::where('rule_id', $rule->id)->where('record_type', 'd')
            ->where('record_at', (int) strtotime('2026-11-25 00:00:00 UTC'))->first();
        $this->assertSame(3600, (int) $d24->u);
        $this->assertSame(3600, (int) $d25->u);
        $this->assertSame(1800, (int) $d24->d);
        $this->assertSame(1800, (int) $d25->d);
        $month = RelayRuleStat::where('rule_id', $rule->id)->where('record_type', 'm')
            ->where('record_at', (int) strtotime('2026-11-01 00:00:00 UTC'))->first();
        $this->assertSame(7200, (int) $month->u);
    }

    public function test_draining_finishes_on_ack(): void
    {
        $machine = $this->enableRelay($this->makeMachine('M1'));
        $rule = $this->makeRule($machine->fresh(), ['listen_port' => 20065]);
        $machines = app(RelayMachineService::class);
        $machine = $machines->beginDrain($machine->fresh());
        $this->assertSame(ServerMachine::RELAY_STATUS_DRAINING, $machine->relay_status);
        $this->assertSame(RelayRule::STATUS_DRAINING, $rule->fresh()->status);

        // The drained (empty) config is what the agent now pulls.
        $latest = app(RelayConfigService::class)->latestForAgent($machine->fresh());
        $this->assertFalse($latest['config']['relay_enabled']);
        $this->assertCount(0, $latest['config']['rules']);

        app(RelayConfigService::class)->handleAck($machine->fresh(), $latest['version'], $latest['hash'], true);
        app(RelayReconcileService::class)->run();
        $machine = $machine->fresh();
        $this->assertSame(ServerMachine::RELAY_STATUS_DISABLED, $machine->relay_status);
        $this->assertFalse($machine->relay_enabled);
        $this->assertSame(RelayRule::STATUS_DISABLED, $rule->fresh()->status);
    }

    public function test_draining_finishes_on_timeout_with_forced_cleanup_record(): void
    {
        $machine = $this->enableRelay($this->makeMachine('M1'));
        $rule = $this->makeRule($machine->fresh(), ['listen_port' => 20066]); // drain_timeout 300
        $machine = app(RelayMachineService::class)->beginDrain($machine->fresh());
        $machine->forceFill(['relay_status_changed_at' => now()->subSeconds(301)])->save();

        app(RelayReconcileService::class)->run();
        $machine = $machine->fresh();
        $this->assertSame(ServerMachine::RELAY_STATUS_DISABLED, $machine->relay_status);
        $log = \App\Models\RelayAuditLog::where('machine_id', $machine->id)
            ->where('action', 'machine.disabled')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('forced cleanup', $log->detail);
    }
}
