<?php

namespace Tests\Feature\Relay;

use App\Models\ServerMachine;
use App\Services\Relay\MachineRoleService;
use App\Services\Relay\RelayMachineService;
use App\Services\Relay\RelayRuleService;
use App\Services\Relay\RelayValidationException;
use Illuminate\Support\Str;

/**
 * Machine roles (owner ruling 2026-11-25): landing or relay, never both.
 * Role is authoritative; switches with existing stock are refused with
 * the cleanup path, never silently cascaded.
 */
class MachineRoleTest extends RelayTestCase
{
    private function bareMachine(string $name, string $role): ServerMachine
    {
        $machine = ServerMachine::create([
            'name' => $name,
            'token' => Str::random(40),
            'is_active' => true,
            'relay_tier' => 'small',
            'role' => $role,
        ]);
        $machine->forceFill(['public_address' => '203.0.113.' . (10 + $machine->id)])->save();
        return $machine->fresh();
    }

    public function test_enable_refused_on_landing_machine(): void
    {
        $landing = $this->bareMachine('LAND', ServerMachine::ROLE_LANDING);
        try {
            app(RelayMachineService::class)->enable($landing);
            $this->fail('enable should be refused on a landing machine');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('role=landing', $e->getMessage());
        }
        $this->assertFalse($landing->fresh()->relay_enabled);
    }

    public function test_rule_create_refused_on_landing_machine(): void
    {
        $landing = $this->bareMachine('LAND', ServerMachine::ROLE_LANDING);
        // Even with the legacy flag forced on at the data level, the role
        // gate fires first.
        $landing->forceFill(['relay_enabled' => true, 'relay_status' => ServerMachine::RELAY_STATUS_ENABLED])->save();
        try {
            app(RelayRuleService::class)->create($landing->fresh(), $this->manualRule());
            $this->fail('rule creation should be refused on a landing machine');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('landing machine', $e->getMessage());
        }
    }

    public function test_switch_to_relay_refused_while_nodes_hosted_then_allowed_after_cleanup(): void
    {
        $machine = $this->bareMachine('SWITCH', ServerMachine::ROLE_LANDING);
        $node = $this->makeNode($machine);
        $roles = app(MachineRoleService::class);
        try {
            $roles->setRole($machine->fresh(), ServerMachine::ROLE_RELAY);
            $this->fail('switch to relay should be refused while nodes are hosted');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('hosts 1 node(s)', $e->getMessage());
        }
        $this->assertSame(ServerMachine::ROLE_LANDING, $machine->fresh()->role);

        $node->delete();
        $roles->setRole($machine->fresh(), ServerMachine::ROLE_RELAY);
        $this->assertSame(ServerMachine::ROLE_RELAY, $machine->fresh()->role);
    }

    public function test_switch_to_landing_refused_with_rules_or_active_relay_then_allowed(): void
    {
        $roles = app(MachineRoleService::class);
        $relay = $this->enableRelay($this->makeMachine('R1'));
        $rule = app(RelayRuleService::class)->create($relay->fresh(), $this->manualRule());

        // Rules exist -> refused.
        try {
            $roles->setRole($relay->fresh(), ServerMachine::ROLE_LANDING);
            $this->fail('switch to landing should be refused while rules exist');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('relay rule(s)', $e->getMessage());
        }

        // Rules deleted but relay still active -> refused.
        app(RelayRuleService::class)->delete($rule->fresh());
        try {
            $roles->setRole($relay->fresh(), ServerMachine::ROLE_LANDING);
            $this->fail('switch to landing should be refused while the relay is active');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('still active', $e->getMessage());
        }

        // Relay disabled at the data level (post-drain end state) -> allowed.
        $relay->forceFill([
            'relay_enabled' => false,
            'relay_status' => ServerMachine::RELAY_STATUS_DISABLED,
        ])->save();
        $roles->setRole($relay->fresh(), ServerMachine::ROLE_LANDING);
        $this->assertSame(ServerMachine::ROLE_LANDING, $relay->fresh()->role);
    }

    public function test_node_assignment_guard(): void
    {
        $relay = $this->bareMachine('RELAY', ServerMachine::ROLE_RELAY);
        $landing = $this->bareMachine('LAND', ServerMachine::ROLE_LANDING);
        $this->assertNotNull(MachineRoleService::nodeAssignmentError($relay->id));
        $this->assertNull(MachineRoleService::nodeAssignmentError($landing->id));
        $this->assertNull(MachineRoleService::nodeAssignmentError(null));
        $this->assertNull(MachineRoleService::nodeAssignmentError(0));
    }

    /**
     * GPT review R11: an address change republishes every follower relay
     * machine independently; a failing follower keeps its old published
     * config and is recorded (report + audit), never silently skipped.
     */
    /** NODE-rule payload targeting $node, in RelayRuleService::create() shape. */
    private function nodeRule(\App\Models\Server $node, int $listenPort): array
    {
        return [
            'name' => 'NODE-' . $listenPort,
            'transport' => 'tcp',
            'listen_ip' => '0.0.0.0',
            'listen_port' => $listenPort,
            'target_type' => 'NODE',
            'target_node_id' => $node->id,
        ];
    }

    public function test_public_address_change_records_per_follower_results_and_isolates_failure(): void
    {
        $landing = $this->bareMachine('LAND-R11', ServerMachine::ROLE_LANDING);
        $node = $this->makeNode($landing);
        $ok = $this->enableRelay($this->makeMachine('R11-OK'));
        $bad = $this->enableRelay($this->makeMachine('R11-BAD'));
        $rules = app(RelayRuleService::class);
        $rules->create($ok, $this->nodeRule($node, 20011), 'admin', 1);
        $rules->create($bad, $this->nodeRule($node, 20012), 'admin', 1);
        $badVersionBefore = \App\Models\RelayConfigOutbox::where('machine_id', $bad->id)->max('version');

        $config = new class($bad->id) extends \App\Services\Relay\RelayConfigService {
            public function __construct(private int $failId)
            {
            }

            public function publish(ServerMachine $machine, string $actorType = 'system', ?int $actorId = null): \App\Models\RelayConfigOutbox
            {
                if ((int) $machine->id === $this->failId) {
                    throw new RelayValidationException('injected publish failure');
                }
                return parent::publish($machine, $actorType, $actorId);
            }
        };
        $roles = new MachineRoleService($config, fn() => true);

        $report = $roles->setPublicAddressWithReport($landing->fresh(), '198.51.100.77', 'admin', 1);

        $byId = collect($report['followers'])->keyBy('machine_id');
        $this->assertSame('published', $byId[$ok->id]['status']);
        $this->assertSame('failed', $byId[$bad->id]['status']);
        $this->assertStringContainsString('injected publish failure', $byId[$bad->id]['error']);

        // The healthy follower really retargeted; the failed one kept its
        // previously published document untouched.
        $okDoc = app(\App\Services\Relay\RelayConfigService::class)->latestForAgent($ok->fresh());
        $this->assertSame('198.51.100.77', $okDoc['config']['rules'][0]['target_host']);
        $this->assertSame($badVersionBefore, \App\Models\RelayConfigOutbox::where('machine_id', $bad->id)->max('version'));

        // Both outcomes are persisted in the audit log, per machine.
        $this->assertTrue(\App\Models\RelayAuditLog::where('machine_id', $ok->id)
            ->where('action', 'machine.public_address.follow')
            ->where('detail', 'like', 'published v%')->exists());
        $this->assertTrue(\App\Models\RelayAuditLog::where('machine_id', $bad->id)
            ->where('action', 'machine.public_address.follow')
            ->where('detail', 'like', 'FAILED:%')->exists());
    }

    /**
     * GPT review R02: a new public address that does not answer on any
     * sampled landing port refuses the whole change — the old address
     * stays in force and no follower is republished.
     */
    public function test_public_address_change_refused_when_new_address_unreachable(): void
    {
        $landing = $this->bareMachine('LAND-R02', ServerMachine::ROLE_LANDING);
        $node = $this->makeNode($landing);
        $relay = $this->enableRelay($this->makeMachine('R02-RELAY'));
        app(RelayRuleService::class)->create($relay, $this->nodeRule($node, 20013), 'admin', 1);
        $versionBefore = \App\Models\RelayConfigOutbox::where('machine_id', $relay->id)->max('version');
        $oldAddress = $landing->fresh()->public_address;

        $roles = new MachineRoleService(app(\App\Services\Relay\RelayConfigService::class), fn() => false);
        try {
            $roles->setPublicAddressWithReport($landing->fresh(), '198.51.100.99', 'admin', 1);
            $this->fail('an unreachable new address must refuse the change');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('precheck failed', $e->getMessage());
        }
        $this->assertSame($oldAddress, $landing->fresh()->public_address);
        $this->assertSame($versionBefore, \App\Models\RelayConfigOutbox::where('machine_id', $relay->id)->max('version'));
        $this->assertTrue(\App\Models\RelayAuditLog::where('machine_id', $landing->id)
            ->where('action', 'machine.public_address.precheck_failed')->exists());
    }

    /**
     * GPT review R05: setRole re-reads the machine under a row lock, so a
     * stale in-memory model cannot switch the role past a node assignment
     * that committed after the model was loaded.
     */
    public function test_set_role_refuses_stale_model_after_concurrent_assignment(): void
    {
        $machine = $this->bareMachine('RACE', ServerMachine::ROLE_LANDING);
        $stale = $machine->fresh(); // loaded while the machine hosts nothing
        $this->makeNode($machine);  // a concurrent assignment commits

        try {
            app(MachineRoleService::class)->setRole($stale, ServerMachine::ROLE_RELAY);
            $this->fail('setRole must re-check stock under the row lock and refuse');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('still hosts', $e->getMessage());
        }
        $this->assertSame(ServerMachine::ROLE_LANDING, $machine->fresh()->role);
    }

    /** GPT review R05: the locked assignment gate used by all write paths. */
    public function test_assert_assignable_locked_rejects_relay_machine(): void
    {
        $relay = $this->makeMachine('R05-RELAY');
        $landing = $this->bareMachine('R05-LAND', ServerMachine::ROLE_LANDING);
        \Illuminate\Support\Facades\DB::transaction(function () use ($relay, $landing) {
            try {
                MachineRoleService::assertAssignableLocked($relay->id);
                $this->fail('a relay machine must refuse node assignment under the lock');
            } catch (RelayValidationException $e) {
                $this->assertStringContainsString('relay machine', $e->getMessage());
            }
            MachineRoleService::assertAssignableLocked($landing->id);
            MachineRoleService::assertAssignableLocked(null);
            $this->addToAssertionCount(1);
        });
    }

    public function test_public_address_validation(): void
    {
        $machine = $this->bareMachine('ADDR', ServerMachine::ROLE_LANDING);
        $roles = app(MachineRoleService::class);
        $roles->setPublicAddress($machine->fresh(), '198.51.100.10');
        $this->assertSame('198.51.100.10', $machine->fresh()->public_address);
        $roles->setPublicAddress($machine->fresh(), 'landing-1.example.test');
        $this->assertSame('landing-1.example.test', $machine->fresh()->public_address);
        try {
            $roles->setPublicAddress($machine->fresh(), 'not a host!!');
            $this->fail('invalid public_address should be rejected');
        } catch (RelayValidationException $e) {
            $this->assertStringContainsString('public_address', $e->getMessage());
        }
    }
}
