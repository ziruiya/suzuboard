<?php

namespace Tests\Feature\Batch3;

use App\Models\Plan;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\NodeSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Support\NodeRedisFake;
use Tests\TestCase;

class NodeEntitlementSyncTest extends TestCase
{
    use RefreshDatabase;

    private NodeRedisFake $redis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redis = new NodeRedisFake();
        Redis::swap($this->redis);
    }

    private function events(): array
    {
        $events = [];
        foreach ($this->redis->published as $payload) {
            $event = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
            if (isset($event['machine_id'])) {
                $events[] = $event['machine_id'];
            } else {
                $events[$event['node_id']][] = [$event['event'], $event['data']];
            }
        }

        return $events;
    }

    private function member(array $attributes): User
    {
        return User::withoutEvents(fn () => User::create($attributes + [
            'email' => Str::uuid() . '@example.test',
            'password' => 'unused-fixture-password',
            'uuid' => Str::uuid()->toString(),
            'token' => Str::random(32),
            'transfer_enable' => 1073741824,
            'expired_at' => time() + 86400,
        ]));
    }

    private function node(int $groupId): Server
    {
        $node = Server::withoutEvents(fn () => Server::create([
            'name' => 'node-' . $groupId,
            'type' => Server::TYPE_VLESS,
            'host' => 'node.example.test',
            'port' => '443',
            'server_port' => 443,
            'group_ids' => [(string) $groupId],
            'enabled' => true,
            'rate' => 1,
        ]));
        Cache::put("node_ws_alive:{$node->id}", true);
        return $node;
    }

    private function plan(): Plan
    {
        return Plan::create([
            'name' => 'Test plan', 'group_id' => 11, 'transfer_enable' => 1,
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
        ]);
    }

    public function test_user_sync_uses_first_committed_group_and_final_group(): void
    {
        [$old, $middle, $new] = [$this->node(11), $this->node(12), $this->node(13)];
        $user = $this->member(['group_id' => 11, 'plan_id' => $this->plan()->id]);
        NodeSyncService::snapshot($old->id, 'sync.users');
        NodeSyncService::snapshot($new->id, 'sync.users');

        DB::transaction(function () use ($user) {
            $user->update(['group_id' => 12]);
            DB::transaction(fn () => $user->update(['group_id' => 13]));
            $user->update(['speed_limit' => 50]);
            $this->assertSame([], $this->events());
        });

        $this->assertSame(['sync.user.delta', ['action' => 'remove', 'users' => [['id' => $user->id]], 'base_revision' => 1, 'state_revision' => 2]],
            $this->events()[$old->id][0]);
        $this->assertArrayNotHasKey($middle->id, $this->events());
        $this->assertCount(1, $this->events()[$new->id]);
        $this->assertCount(1, $this->events()[$old->id]);
        $this->assertSame('add', $this->events()[$new->id][0][1]['action']);
        $this->assertSame(50, $this->events()[$new->id][0][1]['users'][0]['speed_limit']);

        $this->redis->published = [];
        DB::transaction(fn () => $user->update(['group_id' => null]));
        $this->assertSame([$new->id => [['sync.user.delta', ['action' => 'remove', 'users' => [['id' => $user->id]], 'base_revision' => 2, 'state_revision' => 3]]]],
            $this->events());
    }

    public function test_rolled_back_user_changes_do_not_notify(): void
    {
        $this->node(11);
        $this->node(12);
        $user = $this->member(['group_id' => 11, 'plan_id' => $this->plan()->id]);

        DB::beginTransaction();
        $user->update(['group_id' => 12]);
        DB::rollBack();

        $this->assertSame([], $this->events());
        $this->assertSame(11, $user->fresh()->group_id);
    }

    public function test_machine_sync_uses_only_original_and_final_machines_including_detach(): void
    {
        $node = $this->node(11);
        $machines = [];
        foreach (['old', 'intermediate', 'new'] as $name) {
            $machines[] = ServerMachine::create(['name' => $name, 'token' => Str::random(40)])->id;
        }
        [$old, $middle, $new] = $machines;
        $node->forceFill(['machine_id' => $old])->saveQuietly();

        DB::transaction(function () use ($node, $middle, $new) {
            $node->update(['machine_id' => $middle]);
            $node->update(['machine_id' => $new]);
            $this->assertSame([], $this->events());
        });
        $this->assertSame([$old, $new], $this->events());

        $this->redis->published = [];
        DB::transaction(fn () => $node->update(['machine_id' => null]));
        $this->assertSame([$new], $this->events());
    }

    public function test_plan_force_update_refreshes_both_groups_and_handles_quota_only_changes(): void
    {
        $plan = $this->plan();
        $old = $this->node(11);
        $new = $this->node(12);
        $user = $this->member(['group_id' => 11, 'plan_id' => $plan->id, 'u' => 1073741824]);
        Sanctum::actingAs($this->member(['is_admin' => 1]));
        $route = collect(Route::getRoutes()->getRoutes())->first(
            fn ($route) => str_ends_with($route->uri(), '/plan/save')
                && str_contains($route->getActionName(), 'V2\\Admin\\'),
        );

        $payload = [
            'id' => $plan->id, 'name' => $plan->name, 'force_update' => true,
            'group_id' => 12, 'transfer_enable' => 1, 'speed_limit' => 60, 'device_limit' => 3,
        ];
        $this->postJson('/' . $route->uri(), $payload)->assertOk();
        $this->assertSame([['sync.users', ['users' => [], 'state_revision' => 1]]], $this->events()[$old->id]);
        $this->assertSame([['sync.users', ['users' => [], 'state_revision' => 1]]], $this->events()[$new->id]);

        $this->redis->published = [];
        $payload['transfer_enable'] = 2;
        $this->postJson('/' . $route->uri(), $payload)->assertOk();
        $this->assertSame([$new->id => [['sync.users', ['users' => [[
            'id' => $user->id, 'uuid' => $user->uuid, 'speed_limit' => 60, 'device_limit' => 3,
        ]], 'state_revision' => 2]]]], $this->events());
    }
}
