<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\SuzuNodeProfile;
use App\Models\User;
use App\Services\ServerService;
use Tests\Feature\Enterprise\EnterpriseTestCase;

/**
 * AUD-010 / AUD-011: agent delivery isolation.
 *
 * A node may receive config + user lists through agent channels (global
 * server_token via the legacy middleware or ServerV2, machine token via
 * ServerV2 / machine endpoints, WS sync) only when it is in service:
 * enabled, production rollout stage, and — for enterprise inventory
 * nodes — assigned (stock nodes are built show=false and must stay dark).
 *
 * Deliberate exception, guarded by test here: subscription-hidden nodes
 * that are NOT enterprise inventory (the browser-plugin http nodes 52-55
 * in production) are in service by design and keep their agent delivery.
 */
class AgentNodeVisibilityTest extends EnterpriseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        // App\Support\Setting hardcodes Cache::store('redis'); the test
        // environment has no Redis (same reason ServerHandshakeTest is an
        // environmental baseline failure), so back that store with array.
        config()->set('cache.stores.redis', ['driver' => 'array']);
        \Illuminate\Support\Facades\Cache::forgetDriver('redis');
        // The Setting singleton is resolved during app boot with the Redis
        // repository already inside; drop it so it rebuilds on the array store.
        app()->forgetInstance(\App\Support\Setting::class);
        admin_setting([
            'server_token' => 'server-token',
            'server_ws_enable' => 0,
        ]);
    }

    private function liveUser(int $groupId): User
    {
        return $this->makeUser([
            'group_id' => $groupId,
            'transfer_enable' => 100000000,
            'expired_at' => time() + 86400,
        ]);
    }

    private function stockServer(): Server
    {
        $kit = $this->buildReadyKit();
        $serverId = (int) $kit->nodes()->firstOrFail()->server_id;
        // A live user inside the stock group: without the gate this user
        // would be handed to the agent of a hidden, unassigned node.
        $this->liveUser((int) $kit->stock_group_id);
        return Server::findOrFail($serverId);
    }

    /** AUD-010 core: a stock node yields zero users even with a user in its group. */
    public function test_stock_node_gets_no_users_even_with_user_in_stock_group(): void
    {
        $server = $this->stockServer();

        $this->assertFalse($server->show);
        $this->assertCount(0, ServerService::getAvailableUsers($server));
        $this->assertNull(ServerService::getServer($server->id));
        $this->assertFalse(ServerService::isAgentDeliverable($server));
    }

    /** AUD-011: every HTTP agent entry point refuses the stock node. */
    public function test_stock_node_rejected_on_all_agent_http_paths(): void
    {
        $server = $this->stockServer();
        $machine = $this->machine;

        // Legacy middleware (V1 UniProxy) via global server_token.
        $this->getJson("/api/v1/server/UniProxy/user?token=server-token&node_id={$server->id}&node_type=vless")
            ->assertStatus(400);
        $this->getJson("/api/v1/server/UniProxy/config?token=server-token&node_id={$server->id}&node_type=vless")
            ->assertStatus(400);

        // ServerV2 via global server_token.
        $this->getJson("/api/v2/server/user?token=server-token&node_id={$server->id}")
            ->assertStatus(400);
        $this->getJson("/api/v2/server/config?token=server-token&node_id={$server->id}")
            ->assertStatus(400);

        // ServerV2 via machine token.
        $this->getJson("/api/v2/server/user?machine_id={$machine->id}&token={$machine->token}&node_id={$server->id}")
            ->assertStatus(400);
        $this->getJson("/api/v2/server/config?machine_id={$machine->id}&token={$machine->token}&node_id={$server->id}")
            ->assertStatus(400);

        // Machine node list must not advertise the stock node, but must
        // still advertise a production node living on the same machine.
        $production = $this->makePlainServer([$this->ordinaryGroup->id], [
            'machine_id' => $machine->id,
        ]);
        $response = $this->postJson('/api/v2/server/machine/nodes', [
            'machine_id' => $machine->id,
            'token' => $machine->token,
        ])->assertOk();
        $listedIds = collect($response->json('nodes'))->pluck('id')->all();
        $this->assertNotContains($server->id, $listedIds);
        $this->assertContains($production->id, $listedIds);
    }

    /** Disabled nodes are refused on every path (machine path included). */
    public function test_disabled_node_rejected_on_all_paths(): void
    {
        $user = $this->liveUser($this->ordinaryGroup->id);
        $server = $this->makePlainServer([$this->ordinaryGroup->id], [
            'enabled' => false,
            'machine_id' => $this->machine->id,
        ]);

        $this->assertCount(0, ServerService::getAvailableUsers($server));
        $this->assertNull(ServerService::getServer($server->id));

        $this->getJson("/api/v2/server/user?token=server-token&node_id={$server->id}")
            ->assertStatus(400);
        $this->getJson("/api/v2/server/user?machine_id={$this->machine->id}&token={$this->machine->token}&node_id={$server->id}")
            ->assertStatus(400);
        $this->getJson("/api/v1/server/UniProxy/user?token=server-token&node_id={$server->id}&node_type=vless")
            ->assertStatus(400);
        $this->assertNotEquals($user->id, null);
    }

    /** A Suzu profile still in a non-production rollout stage keeps the node dark. */
    public function test_non_production_profile_node_rejected(): void
    {
        $this->liveUser($this->ordinaryGroup->id);
        $server = $this->makePlainServer([$this->ordinaryGroup->id]);
        SuzuNodeProfile::create([
            'server_id' => $server->id,
            'profile_code' => 'sw1',
            'schema_version' => 1,
            'relay_policy' => 'required',
            'rollout_stage' => 'pilot',
            'client_policy' => 'standard',
            'udp_enabled' => false,
        ]);

        $this->assertCount(0, ServerService::getAvailableUsers($server));
        $this->assertNull(ServerService::getServer($server->id));
        $this->getJson("/api/v2/server/user?token=server-token&node_id={$server->id}")
            ->assertStatus(400);
    }

    /** Regression guard: an in-service production node is delivered exactly as before. */
    public function test_production_node_delivery_unchanged(): void
    {
        $user = $this->liveUser($this->ordinaryGroup->id);
        $server = $this->makePlainServer([$this->ordinaryGroup->id], [
            'machine_id' => $this->machine->id,
        ]);

        $users = ServerService::getAvailableUsers($server);
        $this->assertTrue($users->contains('id', $user->id));
        $this->assertNotNull(ServerService::getServer($server->id));

        $this->getJson("/api/v2/server/user?token=server-token&node_id={$server->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $user->id]);
        $this->getJson("/api/v2/server/config?token=server-token&node_id={$server->id}")
            ->assertOk();
        $this->getJson("/api/v1/server/UniProxy/user?token=server-token&node_id={$server->id}&node_type=vless")
            ->assertOk()
            ->assertJsonFragment(['id' => $user->id]);
        $this->getJson("/api/v2/server/user?machine_id={$this->machine->id}&token={$this->machine->token}&node_id={$server->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $user->id]);
    }

    /**
     * Browser-plugin style nodes (production ids 52-55) are show=false but
     * are NOT enterprise inventory: they are in service by design and their
     * agents must keep receiving users, or the browser lines go dark.
     */
    public function test_hidden_non_enterprise_http_node_keeps_delivery(): void
    {
        $user = $this->liveUser($this->vipGroup->id);
        $server = Server::create([
            'name' => '浏览器插件-测试',
            'type' => Server::TYPE_HTTP,
            'host' => 'browser.example.test',
            'port' => '2037',
            'server_port' => 8447,
            'group_ids' => [(string) $this->vipGroup->id],
            'show' => false,
            'enabled' => true,
            'rate' => 1,
            'sort' => 900,
            'machine_id' => $this->machine->id,
            'protocol_settings' => ['tls' => 0, 'tls_settings' => []],
        ]);

        $this->assertTrue(ServerService::isAgentDeliverable($server));
        $this->assertTrue(ServerService::getAvailableUsers($server)->contains('id', $user->id));
        $this->getJson("/api/v2/server/user?machine_id={$this->machine->id}&token={$this->machine->token}&node_id={$server->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $user->id]);
    }
}
