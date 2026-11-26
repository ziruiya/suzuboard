<?php

namespace Tests\Feature\Enterprise;

use App\Http\Controllers\V1\User\EnterpriseLineController;
use App\Models\EnterpriseKitContractSnapshot;
use App\Models\ServerMachine;
use App\Services\Enterprise\EnterpriseAssignmentService;
use App\Services\Enterprise\EnterpriseInventoryHealthService;
use App\Services\Machine\PortRotationService;
use App\Services\Relay\RelayMachineService;
use App\Services\Relay\RelayRuleService;
use App\Services\UserService;
use Illuminate\Http\Request;

class PortRotationInventoryTest extends EnterpriseTestCase
{
    public function test_inventory_ports_and_contract_follow_relay_rotation(): void
    {
        $this->machine->update(['public_address' => '203.0.113.10']);
        $relay = ServerMachine::create([
            'name' => 'Relay fixture', 'token' => 'test-relay-token', 'is_active' => true,
            'role' => ServerMachine::ROLE_RELAY, 'relay_tier' => 'small',
        ]);
        app(RelayMachineService::class)->enable($relay);
        $group = $this->makeGroup('Legacy kit');
        $user = $this->makeUser(['group_id' => $group->id]);
        $servers = [];
        foreach ([20200, 20201] as $port) {
            $server = $this->makePlainServer([$group->id], [
                'machine_id' => $this->machine->id, 'port' => (string) $port,
                'server_port' => $port + 100, 'sort' => $port,
            ]);
            app(RelayRuleService::class)->create($relay, [
                'name' => 'Inventory relay ' . $port, 'transport' => 'tcp',
                'listen_port' => $port, 'target_type' => 'NODE', 'target_node_id' => $server->id,
            ]);
            $servers[] = $server;
        }

        $kit = app(EnterpriseAssignmentService::class)->registerLegacy(
            $user, $this->purpose, $group->id, array_column($servers, 'id')
        );
        $kit->load('nodes');
        $results = collect(app(PortRotationService::class)->rotateForUser($user))->keyBy('server_id');
        $this->assertSame(2, $kit->fresh()->current_contract_version);

        foreach ($servers as $server) {
            $result = $results[$server->id];
            $node = $kit->nodes()->where('server_id', $server->id)->sole();
            $contract = EnterpriseKitContractSnapshot::where('kit_id', $kit->id)
                ->where('server_id', $server->id)->orderBy('contract_version')->get();
            $this->assertSame($result['entry_port'], $node->port);
            $this->assertSame($result['new_port'], $node->landing_port);
            $this->assertSame((int) $server->port, $contract[0]->entry_port);
            $this->assertSame($node->port, $contract[1]->entry_port);
            $this->assertSame($node->landing_port, $contract[1]->target_port);
        }

        $request = Request::create('/api/v1/user/enterprise/lines');
        $request->setUserResolver(fn () => $user);
        $response = app(EnterpriseLineController::class)->lines($request, app(UserService::class));
        $this->assertSame($results[$servers[0]->id]['entry_port'], $response->getData(true)['data']['primary']['entry_port']);

        $verification = app(EnterpriseInventoryHealthService::class)->verifyDelivery($kit);
        $this->assertTrue($verification['ok'], json_encode($verification['checks']));
    }
}
