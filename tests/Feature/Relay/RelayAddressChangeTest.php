<?php

namespace Tests\Feature\Relay;

use App\Models\RelayAddressChange;
use App\Models\RelayAuditLog;
use App\Models\RelayConfigOutbox;
use App\Models\ServerMachine;
use App\Services\Relay\MachineRoleService;
use App\Services\Relay\RelayConfigService;
use App\Services\Relay\RelayReconcileService;
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

    /**
     * N02 (GPT round 3): the crash window round 2 left open — the
     * change record exists with a `pending` follower (the address was
     * saved, then the process died before the publish loop ran), and
     * resume only retried `failed`, so the record could never
     * converge. Simulated by writing the durable record directly,
     * then driving ONLY the reconcile entry point: the follower must
     * be published, ACK, and the change must complete.
     */
    public function test_pending_follower_record_recovers_through_reconcile(): void
    {
        $landing = $this->bareMachine('LAND-N02', ServerMachine::ROLE_LANDING);
        $relay = $this->enableRelay($this->makeMachine('R-N02'));
        $node = $this->makeNode($landing);
        app(RelayRuleService::class)->create($relay, $this->nodeRule($node->id, 20401), 'admin', 1);

        // The address reached the database and the record was created;
        // the process died before any follower was published.
        $newAddress = '198.51.100.110';
        $landing->forceFill(['public_address' => $newAddress])->save();
        $change = RelayAddressChange::create([
            'machine_id' => $landing->id,
            'address' => $newAddress,
            'actor_type' => 'admin',
            'actor_id' => 1,
            'status' => RelayAddressChange::STATUS_IN_PROGRESS,
            'followers' => [$relay->id => ['status' => 'pending', 'attempts' => 0]],
            'precheck' => null,
        ]);
        $versionBefore = (int) $relay->fresh()->relay_config_version;

        // The reconcile loop is the only driver from here on.
        app(RelayReconcileService::class)->run();
        $change = $change->fresh();
        $follower = $change->followers[$relay->id];
        $this->assertSame('published', $follower['status'], 'a pending follower must be resumed, not skipped');
        $this->assertSame((int) $relay->fresh()->relay_config_version, (int) $follower['version']);
        $this->assertGreaterThan($versionBefore, (int) $follower['version']);
        $this->assertSame(RelayAddressChange::STATUS_AWAITING_ACK, $change->status);

        // A repeated resume before the ACK must not inflate the
        // follower's config version (idempotency).
        $roles = app(MachineRoleService::class);
        $roles->resumeAddressChange($change->fresh(), 'system');
        $this->assertSame((int) $follower['version'], (int) $relay->fresh()->relay_config_version);

        // The agent ACKs the published document; the next reconcile
        // pass confirms the follower and completes the change.
        $config = app(RelayConfigService::class);
        $doc = $config->latestForAgent($relay->fresh());
        $this->assertTrue($config->handleAck($relay->fresh(), $doc['version'], $doc['hash'], true));
        app(RelayReconcileService::class)->run();
        $change = $change->fresh();
        $this->assertSame(RelayAddressChange::STATUS_COMPLETE, $change->status);
        $this->assertSame('confirmed', $change->followers[$relay->id]['status']);
        $this->assertNotNull($change->completed_at);
    }

    /**
     * N02 idempotency, the other crash window: the publish DID land
     * before the interruption, only the outcome note is missing (the
     * follower reads `pending` although the new document is already
     * the machine's latest). Resume must recognise the published
     * version instead of publishing again and inflating the version.
     */
    public function test_resume_recognises_already_published_follower_without_republishing(): void
    {
        $landing = $this->bareMachine('LAND-N02-IDEM', ServerMachine::ROLE_LANDING);
        $relay = $this->enableRelay($this->makeMachine('R-N02-IDEM'));
        $node = $this->makeNode($landing);
        app(RelayRuleService::class)->create($relay, $this->nodeRule($node->id, 20411), 'admin', 1);

        $roles = new MachineRoleService(app(RelayConfigService::class), fn () => true);
        $report = $roles->setPublicAddressWithReport($landing->fresh(), '198.51.100.111', 'admin', 1);
        $change = RelayAddressChange::find($report['address_change_id']);
        $publishedVersion = (int) $change->followers[$relay->id]['version'];

        // Rewind the follower state to the crash window.
        $followers = $change->followers;
        $followers[$relay->id] = ['status' => 'pending', 'attempts' => 0];
        $change->forceFill([
            'followers' => $followers,
            'status' => RelayAddressChange::STATUS_IN_PROGRESS,
        ])->save();

        $change = $roles->resumeAddressChange($change->fresh(), 'admin', 1);
        $follower = $change->followers[$relay->id];
        $this->assertSame('published', $follower['status']);
        $this->assertSame($publishedVersion, (int) $follower['version'],
            'resume must adopt the version that already carries the change, not publish a new one');
        $this->assertSame(0, (int) $follower['attempts'], 'no publish was attempted');
        $this->assertSame($publishedVersion, (int) $relay->fresh()->relay_config_version,
            'the config version must not inflate on resume');
    }

    /**
     * N02 task association: consecutive changes A -> B on the same
     * landing machine. The agent applies only B's document. Judging
     * change A afterwards must NOT complete it off the back of B's
     * higher applied version — the applied document does not embody
     * A's retarget. A closes as superseded; B completes.
     */
    public function test_newer_change_supersedes_older_change_instead_of_completing_it(): void
    {
        $landing = $this->bareMachine('LAND-N02-AB', ServerMachine::ROLE_LANDING);
        $relay = $this->enableRelay($this->makeMachine('R-N02-AB'));
        $node = $this->makeNode($landing);
        app(RelayRuleService::class)->create($relay, $this->nodeRule($node->id, 20421), 'admin', 1);

        $roles = new MachineRoleService(app(RelayConfigService::class), fn () => true);
        $first = $roles->setPublicAddressWithReport($landing->fresh(), '198.51.100.120', 'admin', 1);
        $second = $roles->setPublicAddressWithReport($landing->fresh(), '198.51.100.121', 'admin', 1);
        $this->assertNotSame($first['address_change_id'], $second['address_change_id']);

        // The agent applies only the newest document (B's retarget).
        $config = app(RelayConfigService::class);
        $doc = $config->latestForAgent($relay->fresh());
        $this->assertTrue($config->handleAck($relay->fresh(), $doc['version'], $doc['hash'], true));

        $firstChange = $roles->resumeAddressChange(RelayAddressChange::find($first['address_change_id']), 'admin', 1);
        $this->assertSame('superseded', $firstChange->followers[$relay->id]['status'],
            'an applied document carrying B\'s target must not confirm A\'s change');
        $this->assertSame(RelayAddressChange::STATUS_SUPERSEDED, $firstChange->status);
        $this->assertNull($firstChange->completed_at, 'a superseded change is never announced complete');
        $this->assertFalse($firstChange->isOpen());

        $secondChange = $roles->resumeAddressChange(RelayAddressChange::find($second['address_change_id']), 'admin', 1);
        $this->assertSame('confirmed', $secondChange->followers[$relay->id]['status']);
        $this->assertSame(RelayAddressChange::STATUS_COMPLETE, $secondChange->status);
        $this->assertNotNull($secondChange->completed_at);
    }
}
