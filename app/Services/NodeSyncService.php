<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class NodeSyncService
{
    /**
     * AUD-035: Redis set of node ids whose agent-side user list may be
     * stale. The pub/sub pushes below are fire-and-forget: a delta issued
     * while a node is offline (or while the publish itself fails) is
     * lost. Every user-affecting notify therefore also records the node
     * here, and the `suzu:node-resync` command (every minute) re-pushes
     * the full user list — built from the database, the source of truth —
     * to any marked node that is online, then clears the mark. The
     * instant push behaviour is unchanged; this is only the safety net
     * behind it (node reconnects already trigger a full sync as well).
     */
    public const USERS_RESYNC_PENDING_KEY = 'node:users_resync_pending';

    public static function markUsersResyncPending(int $nodeId): void
    {
        try {
            Redis::sadd(self::USERS_RESYNC_PENDING_KEY, $nodeId);
        } catch (\Throwable $e) {
            Log::warning("[NodePush] Redis resync mark failed: {$e->getMessage()}", [
                'node_id' => $nodeId,
            ]);
        }
    }

    public static function clearUsersResyncPending(int $nodeId): void
    {
        try {
            Redis::srem(self::USERS_RESYNC_PENDING_KEY, $nodeId);
        } catch (\Throwable $e) {
            Log::warning("[NodePush] Redis resync clear failed: {$e->getMessage()}", [
                'node_id' => $nodeId,
            ]);
        }
    }

    /**
     * Check if node has active WS connection
     */
    public static function isNodeOnline(int $nodeId): bool
    {
        return (bool) Cache::get("node_ws_alive:{$nodeId}");
    }

    /**
     * Push node config update
     */
    public static function notifyConfigUpdated(int $nodeId): void
    {
        if (!self::isNodeOnline($nodeId))
            return;

        $node = Server::find($nodeId);
        if (!$node)
            return;

        if (!ServerService::isAgentDeliverable($node))
            return;

        self::push($nodeId, 'sync.config', ['config' => ServerService::buildNodeConfig($node)]);
    }

    /**
     * Push all users to all nodes in the group
     */
    public static function notifyUsersUpdatedByGroup(int $groupId): void
    {
        $servers = Server::whereJsonContains('group_ids', (string) $groupId)
            ->get();

        foreach ($servers as $server) {
            self::markUsersResyncPending($server->id);
            if (!self::isNodeOnline($server->id))
                continue;

            $users = ServerService::getAvailableUsers($server)->toArray();
            self::push($server->id, 'sync.users', ['users' => $users]);
        }
    }

    /**
     * Push user changes (add/remove) to affected nodes
     */
    public static function notifyUserChanged(User $user): void
    {
        if (!$user->group_id)
            return;

        $servers = Server::whereJsonContains('group_ids', (string) $user->group_id)->get();
        foreach ($servers as $server) {
            self::markUsersResyncPending($server->id);
            if (!self::isNodeOnline($server->id))
                continue;

            if ($user->isAvailable()) {
                self::push($server->id, 'sync.user.delta', [
                    'action' => 'add',
                    'users' => [
                        [
                            'id' => $user->id,
                            'uuid' => $user->uuid,
                            'speed_limit' => $user->speed_limit,
                            'device_limit' => $user->device_limit,
                        ]
                    ],
                ]);
            } else {
                self::push($server->id, 'sync.user.delta', [
                    'action' => 'remove',
                    'users' => [['id' => $user->id]],
                ]);
            }
        }
    }

    /**
     * Push user removal from a specific group's nodes
     */
    public static function notifyUserRemovedFromGroup(int $userId, int $groupId): void
    {
        $servers = Server::whereJsonContains('group_ids', (string) $groupId)
            ->get();

        foreach ($servers as $server) {
            self::markUsersResyncPending($server->id);
            if (!self::isNodeOnline($server->id))
                continue;

            self::push($server->id, 'sync.user.delta', [
                'action' => 'remove',
                'users' => [['id' => $userId]],
            ]);
        }
    }

    /**
     * Full sync: push config + users to a node
     */
    public static function notifyFullSync(int $nodeId): void
    {
        if (!self::isNodeOnline($nodeId))
            return;

        $node = Server::find($nodeId);
        if (!$node)
            return;

        if (!ServerService::isAgentDeliverable($node))
            return;

        self::push($nodeId, 'sync.config', ['config' => ServerService::buildNodeConfig($node)]);

        $users = ServerService::getAvailableUsers($node)->toArray();
        self::push($nodeId, 'sync.users', ['users' => $users]);
    }

    /**
     * Notify machine that its node set has changed.
     * Always publishes via Redis so the WS process can update its in-memory registry.
     */
    public static function notifyMachineNodesChanged(int $machineId): void
    {
        $machine = ServerMachine::find($machineId);

        $nodeList = [];
        if ($machine) {
            $nodes = ServerService::getMachineNodes($machine);
            $nodeList = $nodes->map(fn($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'name' => $n->name,
            ])->values()->toArray();
        }

        // Always publish via Redis so the WS process can update its in-memory registry
        self::pushMachine($machineId, 'sync.nodes', ['nodes' => $nodeList]);
    }

    /**
     * Publish a push command to Redis — picked up by the Workerman WS server
     */
    public static function push(int $nodeId, string $event, array $data): void
    {
        try {
            Redis::publish('node:push', json_encode([
                'node_id' => $nodeId,
                'event' => $event,
                'data' => $data,
            ]));
        } catch (\Throwable $e) {
            Log::warning("[NodePush] Redis publish failed: {$e->getMessage()}", [
                'node_id' => $nodeId,
                'event' => $event,
            ]);
        }
    }

    /**
     * Publish a machine-level push command to Redis — picked up by the Workerman WS server
     */
    public static function pushMachine(int $machineId, string $event, array $data): void
    {
        try {
            Redis::publish('node:push', json_encode([
                'machine_id' => $machineId,
                'event' => $event,
                'data' => $data,
            ]));
        } catch (\Throwable $e) {
            Log::warning("[NodePush] Redis machine publish failed: {$e->getMessage()}", [
                'machine_id' => $machineId,
                'event' => $event,
            ]);
        }
    }
}
