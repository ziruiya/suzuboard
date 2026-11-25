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
