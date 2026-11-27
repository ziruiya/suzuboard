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
    public const CONFIG_RESYNC_PENDING_KEY = 'node:config_resync_pending';

    public static function updateConfigResyncPending(int $nodeId, int $revision, bool $clear): void
    {
        try {
            $updated = Redis::eval(<<<'LUA'
                local pending = redis.call('HGET', KEYS[1], ARGV[1])
                local revision = ARGV[2]
                -- Compare decimal strings to preserve the full Redis integer range.
                local newer = pending and (#pending > #revision or (#pending == #revision and pending > revision))
                if not newer then
                    if ARGV[3] == '1' then
                        redis.call('HDEL', KEYS[1], ARGV[1])
                    else
                        redis.call('HSET', KEYS[1], ARGV[1], revision)
                    end
                end
                return 1
                LUA, 1, self::CONFIG_RESYNC_PENDING_KEY, (string) $nodeId, (string) $revision, $clear ? '1' : '0');
            if ($updated === false) {
                throw new \RuntimeException('Redis rejected the config resync update');
            }
        } catch (\Throwable $e) {
            Log::warning("[NodePush] Redis config resync update failed: {$e->getMessage()}", [
                'node_id' => $nodeId,
                'state_revision' => $revision,
                'clear' => $clear,
            ]);
        }
    }

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

        // 机器口径：推送只发往该节点已注册的连接（库存节点只可能由所属
        // 机器注册），库存节点的配置热更新必须能推达。
        if (!ServerService::isMachineDeliverable($node))
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

        if (!ServerService::isMachineDeliverable($node))
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

    public static function revisionDomain(string $event): ?string
    {
        return match ($event) {
            'sync.config' => 'config',
            'sync.users', 'sync.user.delta' => 'users',
            default => null,
        };
    }

    private static function revisionKey(int $nodeId, string $event): ?string
    {
        $domain = self::revisionDomain($event);
        return $domain === null ? null : "node:state_revision:{$nodeId}:{$domain}";
    }

    public static function currentRevision(int $nodeId, string $event): int
    {
        $key = self::revisionKey($nodeId, $event);
        if ($key === null) {
            throw new \InvalidArgumentException("No revision domain for {$event}");
        }

        return (int) Redis::get($key);
    }

    /**
     * Publish in the same Redis operation as revision allocation, so concurrent
     * publishers reach the worker in revision order.
     */
    public static function push(int $nodeId, string $event, array $data): void
    {
        try {
            $key = self::revisionKey($nodeId, $event);
            if ($key === null) {
                Redis::publish('node:push', json_encode([
                    'node_id' => $nodeId,
                    'event' => $event,
                    'data' => $data,
                ], JSON_THROW_ON_ERROR));
                return;
            }

            unset($data['state_revision'], $data['base_revision']);
            $header = json_encode(['node_id' => $nodeId, 'event' => $event], JSON_THROW_ON_ERROR);
            // Encode the original fields in PHP, preserving empty arrays and large integers.
            $fields = json_encode((object) $data, JSON_THROW_ON_ERROR);
            $published = Redis::eval(<<<'LUA'
                local previous = redis.call('GET', KEYS[1]) or '0'
                redis.call('INCR', KEYS[1])
                local revision = redis.call('GET', KEYS[1])
                local fields = '"state_revision":' .. revision
                if ARGV[4] == '1' then
                    fields = fields .. ',"base_revision":' .. previous
                end
                local suffix = ARGV[3] == '{}' and '}' or ',' .. string.sub(ARGV[3], 2)
                return redis.call('PUBLISH', ARGV[1], ARGV[2] .. fields .. suffix .. '}')
                LUA, 1, $key, config('database.redis.options.prefix', '') . 'node:push',
                substr($header, 0, -1) . ',"data":{', $fields, $event === 'sync.user.delta' ? '1' : '0');
            if ($published === false) {
                throw new \RuntimeException('Redis rejected the revision update');
            }
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
            ], JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            Log::warning("[NodePush] Redis machine publish failed: {$e->getMessage()}", [
                'machine_id' => $machineId,
                'event' => $event,
            ]);
        }
    }
}
