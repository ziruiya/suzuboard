<?php

namespace Tests\Feature\Server;

use App\Models\Server;
use App\Models\SuzuNodeProfile;
use App\Models\User;
use App\Services\ServerService;
use Tests\Feature\Enterprise\EnterpriseTestCase;

/**
 * AUD-010 / AUD-011: agent delivery isolation — two scopes.
 *
 * Strict scope (isAgentDeliverable): channels with no machine-ownership
 * proof — the global server_token (legacy middleware, ServerV2 token
 * auth, WS single-node auth via getServer) and relay NODE targets.
 * A node is deliverable there only when in service: enabled, production
 * rollout stage, and — for enterprise inventory nodes — assigned (stock
 * nodes are built show=false; E11: unauthorized parties must not pull
 * them). This scope is unchanged by the 2026-11-25 fix.
 *
 * Machine scope (isMachineDeliverable): delivery to the node's OWN
 * machine agent (machine token / WS machine auth / machine node list).
 * The stock show gate does NOT apply here — a stock node whose config
 * never reaches its machine never listens on its landing port, so the
 * inventory would be fake (production evidence 2026-11-25: all 48 stock
 * nodes missing from machine delivery, no landing listeners). The user
 * list stays converged by group isolation instead: a stock node's
 * group_ids are exactly its kit group, whose only members are the
 * enterprise probe identity (transiently, during verification) and the
 * assigned customer. Enabled + production-rollout gates apply in both
 * scopes.
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

    public function test_subscription_excludes_disabled_visible_nodes(): void
    {
        $group = (int) $this->ordinaryGroup->id;
        $user = $this->liveUser($group);
        $live = $this->makePlainServer([$group]);
        $disabled = $this->makePlainServer([$group], ['enabled' => false]);

        $ids = array_column(ServerService::getAvailableServers($user), 'id');
        $this->assertContains($live->id, $ids);
        $this->assertNotContains($disabled->id, $ids);
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

    /**
     * AUD-010 core, strict scope (unchanged): through the global-token
     * lens a stock node does not exist — getServer hides it and the
     * strict predicate stays false while it is unassigned (show=false).
     */
    public function test_stock_node_strict_gate_stays_closed(): void
    {
        $server = $this->stockServer();

        $this->assertFalse($server->show);
        $this->assertNull(ServerService::getServer($server->id));
        $this->assertFalse(ServerService::isAgentDeliverable($server));
    }

    /**
     * Machine scope: the stock node IS deliverable to its own machine,
     * and its user list is exactly the kit-group membership — the user
     * stockServer() placed in the stock group stands in for the probe
     * identity / assigned customer; nobody outside the group leaks in.
     * Subscription rendering still hides the node (show gate intact).
     */
    public function test_stock_node_machine_delivery_and_group_scoped_users(): void
    {
        $server = $this->stockServer();
        $kitGroupUser = ServerService::getAvailableUsers($server);
        $this->assertTrue(ServerService::isMachineDeliverable($server));
        $this->assertCount(1, $kitGroupUser);

        $outsider = $this->liveUser($this->ordinaryGroup->id);
        $users = ServerService::getAvailableUsers($server);
        $this->assertFalse($users->contains('id', $outsider->id));

        // getMachineNodes (machine list source) must include the node.
        $listed = ServerService::getMachineNodes($this->machine)->pluck('id')->all();
        $this->assertContains($server->id, $listed);

        // Subscription visibility unchanged: a kit-group user does NOT
        // see the unassigned stock node in their subscription render.
        $member = User::findOrFail($kitGroupUser->first()->id);
        $this->assertNotContains($server->id, $this->subscriptionServerIds($member));
    }

    /** An unassigned kit group with no members yields an empty user list. */
    public function test_stock_node_users_empty_until_probe_or_customer_joins(): void
    {
        $kit = $this->buildReadyKit();
        $server = Server::findOrFail((int) $kit->nodes()->firstOrFail()->server_id);

        $this->assertCount(0, ServerService::getAvailableUsers($server));

        $probe = $this->liveUser((int) $kit->stock_group_id);
        $users = ServerService::getAvailableUsers($server);
        $this->assertCount(1, $users);
        $this->assertTrue($users->contains('id', $probe->id));
    }

    /**
     * AUD-011 split by scope: global-token HTTP paths refuse the stock
     * node; machine-token paths (the node's own machine) deliver config
     * and the group-scoped user list; the machine node list advertises
     * the stock node alongside production nodes on the same machine.
     */
    public function test_stock_node_http_paths_split_by_scope(): void
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

        // ServerV2 via machine token (own machine): delivered.
        $kitUserId = (int) ServerService::getAvailableUsers($server)->first()->id;
        $this->getJson("/api/v2/server/user?machine_id={$machine->id}&token={$machine->token}&node_id={$server->id}")
            ->assertOk()
            ->assertJsonFragment(['id' => $kitUserId]);
        $this->getJson("/api/v2/server/config?machine_id={$machine->id}&token={$machine->token}&node_id={$server->id}")
            ->assertOk();

        // Machine node list advertises the stock node AND a production
        // node living on the same machine.
        $production = $this->makePlainServer([$this->ordinaryGroup->id], [
            'machine_id' => $machine->id,
        ]);
        $response = $this->postJson('/api/v2/server/machine/nodes', [
            'machine_id' => $machine->id,
            'token' => $machine->token,
        ])->assertOk();
        $listedIds = collect($response->json('nodes'))->pluck('id')->all();
        $this->assertContains($server->id, $listedIds);
        $this->assertContains($production->id, $listedIds);
    }

    /**
     * Lifecycle: assignment flips show=true (EnterpriseAssignmentService),
     * after which even the strict scope opens — the customer-facing state
     * behaves exactly like any production node.
     */
    public function test_assigned_stock_node_opens_strict_scope(): void
    {
        $server = $this->stockServer();
        $server->show = true;
        $server->save();

        $this->assertTrue(ServerService::isAgentDeliverable($server->fresh()));
        $this->assertNotNull(ServerService::getServer($server->id));
        $this->getJson("/api/v2/server/user?token=server-token&node_id={$server->id}")
            ->assertOk();
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
