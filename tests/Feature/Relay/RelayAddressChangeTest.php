<?php

namespace Tests\Feature\Relay;

use App\Models\RelayAddressChange;
use App\Models\RelayAuditLog;
use App\Models\RelayConfigOutbox;
use App\Models\ServerMachine;
use App\Services\Relay\MachineRoleService;
use App\Services\Relay\RelayConfigService;
use App\Services\Relay\RelayRuleService;
use App\Services\Relay\RelayValidationException;

/**
 * GPT incremental review round 2, item B03 (R02/R11 completion):
 *
 *  - the address-change precheck probes EVERY affected target port,
 *    not a sample of five with an any-pass rule (GPT reproduced: eight
 *    targets, seven dead, change went through);
 *  - a follower that fails mid-change leaves a durable, resumable
 *    record — not just an audit line — and the resume path converges
 *    every follower's applied version;
 *  - the rollback reachability acceptance (B04) rides on the same
 *    all-targets TCP standard instead of a separate mechanism.
 */
class RelayAddressChangeTest extends RelayTestCase
{
    private function bareMachine(string $name, string $role): ServerMachine
    {
        $machine = ServerMachine::create([
            'name' => $name,
            'token' => \Illuminate\Support\Str::random(40),
            'is_active' => true,
            'relay_tier' => 'small',
            'role' => $role,
        ]);
        $machine->forceFill(['public_address' => '203.0.113.' . (10 + $machine->id)])->save();
        return $machine->fresh();
    }

    private function nodeRule(int $nodeId, int $listenPort): array
    {
        return [
            'name' => 'NODE-' . $listenPort,
            'transport' => 'tcp',
            'listen_ip' => '0.0.0.0',
            'listen_port' => $listenPort,
            'target_type' => 'NODE',
            'target_node_id' => $nodeId,
        ];
    }

    /**
     * Eight affected targets on two landing ports; the new address
     * answers on one port only. Round 1 passed this change (sample of
     * five, any-pass). B03 must refuse it and name the dead port.
     */
    public function test_precheck_probes_every_affected_port_and_refuses_on_any_failure(): void
    {
        $landing = $this->bareMachine('LAND-B03', ServerMachine::ROLE_LANDING);
        $relay = $this->enableRelay($this->makeMachine('R-B03'));
        $rules = app(RelayRuleService::class);
        // Eight targets: four nodes on port 8501, four on port 8502.
        for ($i = 0; $i < 8; $i++) {
            $node = $this->makeNode($landing, ['server_port' => $i < 4 ? 8501 : 8502]);
            $rules->create($relay, $this->nodeRule($node->id, 20301 + $i), 'admin', 1);
        }
        $oldAddress = $landing->fresh()->public_address;
        $versionBefore = RelayConfigOutbox::where('machine_id', $relay->id)->max('version');

        $probed = [];
        $roles = new MachineRoleService(
            app(RelayConfigService::class),
            function (string $host, int $port, float $timeout) use (&$probed): bool {
                $probed[] = $port;
                return $port === 8501; // 8502 is dead at the new address
            }
        );
        try {
            $roles->setPublicAddressWithReport($landing->fresh(), '198.51.100.88', 'admin', 1);
            $this->fail('a change with a dead affected port must be refused');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('precheck failed', $e->getMessage());
            $this->assertStringContainsString('8502', $e->getMessage());
        }
        sort($probed);
        $this->assertSame([8501, 8502], $probed, 'every affected port must be probed — no sampling');
        $this->assertSame($oldAddress, $landing->fresh()->public_address);
        $this->assertSame($versionBefore, RelayConfigOutbox::where('machine_id', $relay->id)->max('version'));
        $this->assertSame(0, RelayAddressChange::count(), 'a refused change leaves no dangling recovery record');
        $this->assertTrue(RelayAuditLog::where('machine_id', $landing->id)
            ->where('action', 'machine.public_address.precheck_failed')->exists());
    }

    /** All ports answer: the change proceeds and the precheck is reported per port. */
    public function test_precheck_passes_when_every_affected_port_answers(): void
    {
        $landing = $this->bareMachine('LAND-B03-OK', ServerMachine::ROLE_LANDING);
        $relay = $this->enableRelay($this->makeMachine('R-B03-OK'));
        $node = $this->makeNode($landing, ['server_port' => 8501]);
        app(RelayRuleService::class)->create($relay, $this->nodeRule($node->id, 20311), 'admin', 1);

        $roles = new MachineRoleService(app(RelayConfigService::class), fn () => true);
        $report = $roles->setPublicAddressWithReport($landing->fresh(), '198.51.100.89', 'admin', 1);

        $this->assertSame([8501], $report['precheck']['probed']);
        $this->assertSame([8501], $report['precheck']['passed']);
        $this->assertSame([], $report['precheck']['failed']);
        $this->assertSame('198.51.100.89', $landing->fresh()->public_address);
        $change = RelayAddressChange::find($report['address_change_id']);
        $this->assertNotNull($change);
        $this->assertSame(RelayAddressChange::STATUS_AWAITING_ACK, $change->status);
    }

    /**
     * A follower whose publish fails mid-change is recorded on the
     * change as failed; resuming the change (the reconcile/admin path)
     * republishes it, and once the agent ACKs, every follower is
     * confirmed and the change completes — versions converged.
     */
    public function test_failed_follower_is_recorded_and_resume_converges_versions(): void
    {
        $landing = $this->bareMachine('LAND-B03-REC', ServerMachine::ROLE_LANDING);
        $node = $this->makeNode($landing);
        $ok = $this->enableRelay($this->makeMachine('R-B03-OK2'));
        $bad = $this->enableRelay($this->makeMachine('R-B03-BAD2'));
        $rules = app(RelayRuleService::class);
        $rules->create($ok, $this->nodeRule($node->id, 20321), 'admin', 1);
        $rules->create($bad, $this->nodeRule($node->id, 20322), 'admin', 1);

        $flaky = new class($bad->id) extends RelayConfigService {
            public bool $fail = true;

            public function __construct(private int $failId)
            {
            }

            public function publish(ServerMachine $machine, string $actorType = 'system', ?int $actorId = null): RelayConfigOutbox
            {
                if ($this->fail && (int) $machine->id === $this->failId) {
                    throw new RelayValidationException('injected publish failure');
                }
                return parent::publish($machine, $actorType, $actorId);
            }
        };
        $roles = new MachineRoleService($flaky, fn () => true);
        $report = $roles->setPublicAddressWithReport($landing->fresh(), '198.51.100.90', 'admin', 1);

        $change = RelayAddressChange::find($report['address_change_id']);
        $this->assertSame(RelayAddressChange::STATUS_PARTIAL, $change->status);
        $this->assertSame('failed', $change->followers[$bad->id]['status']);
        $this->assertSame('published', $change->followers[$ok->id]['status']);

        // The failure clears; the resume path republishes the follower.
        $flaky->fail = false;
        $change = $roles->resumeAddressChange($change->fresh(), 'admin', 1);
        $this->assertSame('published', $change->followers[$bad->id]['status']);
        $this->assertSame(2, (int) $change->followers[$bad->id]['attempts']);
        $this->assertSame(RelayAddressChange::STATUS_AWAITING_ACK, $change->status);

        // Both agents ACK the versions this change published for them:
        // every follower converges and the change completes.
        $config = app(RelayConfigService::class);
        foreach ([$ok, $bad] as $follower) {
            $doc = $config->latestForAgent($follower->fresh());
            $this->assertTrue($config->handleAck($follower->fresh(), $doc['version'], $doc['hash'], true));
        }
        $change = $roles->resumeAddressChange($change->fresh(), 'admin', 1);
        $this->assertSame(RelayAddressChange::STATUS_COMPLETE, $change->status);
        $this->assertSame('confirmed', $change->followers[$ok->id]['status']);
        $this->assertSame('confirmed', $change->followers[$bad->id]['status']);
        $this->assertNotNull($change->completed_at);
    }

    /**
     * B04: rollback reachability rides on the same probe standard —
     * with a prober wired, a rollback whose re-derived NODE target does
     * not answer is refused before anything is republished; with all
     * targets answering, the same rollback succeeds.
     */
    public function test_rollback_refused_when_rederived_target_unreachable(): void
    {
        $relay = $this->enableRelay($this->makeMachine('R-B04'));
        $landing = $this->bareMachine('LAND-B04', ServerMachine::ROLE_LANDING);
        $node = $this->makeNode($landing, ['server_port' => 8501]);
        $rule = app(RelayRuleService::class)->create($relay, $this->nodeRule($node->id, 20331), 'admin', 1);
        $config = app(RelayConfigService::class);
        $v1 = $config->latestForAgent($relay->fresh());

        // A second publish so there is a newer version to roll back from.
        $this->rules2Publish($relay);
        $versionBefore = (int) $relay->fresh()->relay_config_version;
        $this->assertGreaterThan($v1['version'], $versionBefore);

        $dead = new RelayConfigService(fn () => false);
        try {
            $dead->rollback($relay->fresh(), $v1['version'], 'admin', 1);
            $this->fail('rollback to an unreachable target must be refused');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('unreachable', $e->getMessage());
        }
        $this->assertSame($versionBefore, (int) $relay->fresh()->relay_config_version, 'a refused rollback publishes nothing');
        $this->assertTrue(RelayAuditLog::where('machine_id', $relay->id)
            ->where('action', 'config.rollback_precheck_failed')->exists());

        $alive = new RelayConfigService(fn () => true);
        $outbox = $alive->rollback($relay->fresh(), $v1['version'], 'admin', 1);
        $this->assertSame($versionBefore + 1, (int) $outbox->version);
    }

    private function rules2Publish(ServerMachine $relay): void
    {
        app(RelayRuleService::class)->create($relay, $this->manualRule(['name' => '第二条', 'listen_port' => 20332]), 'admin', 1);
    }
}
