<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class NodeSyncService
{
    public const CONFIG_RESYNC_PENDING_KEY = 'node:config_resync_pending';
    private const USERS_PENDING_KEY = 'node:users_revision_pending';

    public static function isNodeOnline(int $nodeId): bool
    {
        return (bool) Cache::get("node_ws_alive:{$nodeId}");
    }

    public static function notifyConfigUpdated(int $nodeId): void
    {
        self::push($nodeId, 'sync.config');
    }

    public static function notifyUsersUpdatedByGroup(int $groupId): void
    {
        self::notifyGroup($groupId, 'sync.users');
    }

    public static function notifyUserChanged(User $user): void
    {
        if ($user->group_id) {
            self::notifyGroup($user->group_id, 'sync.user.delta', [$user->id]);
        }
    }

    public static function notifyUserRemovedFromGroup(int $userId, int $groupId): void
    {
        self::notifyGroup($groupId, 'sync.user.delta', [$userId]);
    }

    private static function notifyGroup(int $groupId, string $event, array $userIds = []): void
    {
        DB::afterCommit(function () use ($groupId, $event, $userIds) {
            foreach (Server::whereJsonContains('group_ids', (string) $groupId)
                ->orWhereJsonContains('group_ids', $groupId)->get(['id']) as $server) {
                self::push($server->id, $event, ['users' => array_map(fn ($id) => ['id' => $id], $userIds)]);
            }
        });
    }

    public static function notifyFullSync(int $nodeId): void
    {
        self::push($nodeId, 'sync.config');
        self::push($nodeId, 'sync.users');
    }

    public static function notifyMachineNodesChanged(int $machineId): void
    {
        DB::afterCommit(function () use ($machineId) {
            $machine = ServerMachine::find($machineId);
            $nodes = $machine ? ServerService::getMachineNodes($machine)->map(fn ($node) => [
                'id' => $node->id,
                'type' => $node->type,
                'name' => $node->name,
            ])->values()->toArray() : [];

            self::pushMachine($machineId, 'sync.nodes', ['nodes' => $nodes]);
        });
    }

    public static function revisionDomain(string $event): ?string
    {
        return match ($event) {
            'sync.config' => 'config',
            'sync.users', 'sync.user.delta' => 'users',
            default => null,
        };
    }

    public static function currentRevision(int $nodeId, string $event): int
    {
        return self::snapshot($nodeId, $event)['state_revision'] ?? 0;
    }

    public static function snapshot(int $nodeId, string $event, ?callable $allows = null): ?array
    {
        $domain = self::revisionDomain($event) ?? throw new \InvalidArgumentException("No revision domain for {$event}");
        $snapshot = NodeStateSnapshot::read($nodeId, $domain, $allows);
        if ($snapshot) {
            self::mirror($nodeId, $domain, $snapshot['data'], '');
        }

        return $snapshot['data'] ?? null;
    }

    /**
     * Only build the payload after commit. An older callback always reads
     * current DB state, even when callbacks run out of commit order.
     */
    public static function push(int $nodeId, string $event, array $data = []): bool
    {
        $published = false;
        DB::afterCommit(function () use ($nodeId, $event, $data, &$published) {
            try {
                $domain = self::revisionDomain($event);
                if ($domain === null) {
                    $published = self::publish(['node_id' => $nodeId, 'event' => $event, 'data' => $data]);
                    return;
                }

                $snapshot = NodeStateSnapshot::read($nodeId, $domain);
                if (!$snapshot) {
                    return;
                }

                $full = $snapshot['data'];
                $eventData = $full;
                if ($event === 'sync.user.delta') {
                    $delta = self::userDelta($nodeId, $snapshot, array_column($data['users'] ?? [], 'id'));
                    $event = $delta ? 'sync.user.delta' : 'sync.users';
                    $eventData = $delta ?? $full;
                }

                $message = self::isNodeOnline($nodeId)
                    ? json_encode(['node_id' => $nodeId, 'event' => $event, 'data' => $eventData], JSON_THROW_ON_ERROR)
                    : '';
                $published = self::mirror($nodeId, $domain, $full, $message);
            } catch (\Throwable $e) {
                Log::warning('[NodePush] Push failed', ['node_id' => $nodeId, 'event' => $event, 'error' => $e->getMessage()]);
            }
        });

        return $published;
    }

    private static function userDelta(int $nodeId, array $snapshot, array $ids): ?array
    {
        $base = $snapshot['previous'];
        $full = $snapshot['data'];
        if ($base === $full['state_revision'] || !$snapshot['previous_digest'] || !$ids) {
            return null;
        }

        try {
            $cached = Redis::get(self::usersCacheKey($nodeId, $base));
        } catch (\Throwable) {
            return null;
        }
        if (!$cached || hash('sha256', $cached) !== $snapshot['previous_digest']) {
            return null;
        }

        $old = array_column(json_decode($cached, true, flags: JSON_THROW_ON_ERROR)['users'], null, 'id');
        $current = array_column($full['users'], null, 'id');
        $add = [];
        $remove = [];
        foreach ($ids as $id) {
            if (isset($current[$id])) {
                $add[$id] = $old[$id] = $current[$id];
            } elseif (isset($old[$id])) {
                $remove[$id] = ['id' => $id];
                unset($old[$id]);
            }
        }

        // If other commits have been folded into this revision, the suggested
        // increment may not reproduce the snapshot. Send the full state then.
        if ($old != $current || (bool) $add === (bool) $remove) {
            return null;
        }

        return [
            'action' => $add ? 'add' : 'remove',
            'users' => array_values($add ?: $remove),
            'base_revision' => $base,
            'state_revision' => $full['state_revision'],
        ];
    }

    private static function usersCacheKey(int $nodeId, int $revision): string
    {
        return "node:users_snapshot:{$nodeId}:{$revision}";
    }

    private static function pendingKey(string $domain): string
    {
        return $domain === 'config' ? self::CONFIG_RESYNC_PENDING_KEY : self::USERS_PENDING_KEY;
    }

    private static function deliveredKey(string $domain): string
    {
        return "node:state_delivered:{$domain}";
    }

    /**
     * Rebuild the Redis counter mirror from the durable DB revision on every
     * snapshot. A flush loses delivery watermarks, so both domains are resent.
     * Compare decimal strings for revisions outside Lua's precise integer range.
     */
    private static function mirror(int $nodeId, string $domain, array $data, string $message): bool
    {
        $revision = $data['state_revision'];
        try {
            if ($domain === 'users') {
                Redis::setex(self::usersCacheKey($nodeId, $revision), 120, json_encode(['users' => $data['users']], JSON_THROW_ON_ERROR));
            }

            return (int) Redis::eval(<<<'LUA'
                local current = redis.call('GET', KEYS[1])
                local node = ARGV[1]
                local revision = ARGV[2]
                if current and (#current > #revision or (#current == #revision and current > revision)) then
                    return 0
                end
                redis.call('SET', KEYS[1], revision)
                if redis.call('HGET', KEYS[2], node) ~= revision then
                    redis.call('HSET', KEYS[3], node, revision)
                end
                if ARGV[4] ~= '' then
                    return redis.call('PUBLISH', ARGV[3], ARGV[4])
                end
                return 0
                LUA, 3, NodeStateSnapshot::key($nodeId, $domain), self::deliveredKey($domain), self::pendingKey($domain),
                (string) $nodeId, (string) $revision, config('database.redis.options.prefix', '') . 'node:push', $message) > 0;
        } catch (\Throwable $e) {
            Log::warning('[NodePush] Redis mirror or publish failed', ['node_id' => $nodeId, 'domain' => $domain, 'error' => $e->getMessage()]);
            return false;
        }
    }

    private static function needsSync(int $nodeId, string $domain, int $revision): bool
    {
        return (string) Redis::hget(self::deliveredKey($domain), (string) $nodeId) !== (string) $revision;
    }

    public static function confirmDelivery(int $nodeId, string $domain, int $revision): void
    {
        try {
            Redis::eval(<<<'LUA'
                local function newer(a, b)
                    return a and (#a > #b or (#a == #b and a > b))
                end
                local node = ARGV[1]
                local revision = ARGV[2]
                if not newer(redis.call('HGET', KEYS[1], node), revision) then
                    redis.call('HSET', KEYS[1], node, revision)
                end
                if not newer(redis.call('HGET', KEYS[2], node), revision) then
                    redis.call('HDEL', KEYS[2], node)
                end
                LUA, 2, self::deliveredKey($domain), self::pendingKey($domain), (string) $nodeId, (string) $revision);
        } catch (\Throwable $e) {
            Log::warning('[NodePush] Delivery watermark write failed', ['node_id' => $nodeId, 'domain' => $domain, 'error' => $e->getMessage()]);
        }
    }

    public static function reconcile(int $nodeId, string $event): void
    {
        $domain = self::revisionDomain($event);
        $snapshot = NodeStateSnapshot::read($nodeId, $domain)['data'] ?? null;
        if (!$snapshot) {
            return;
        }

        $message = self::needsSync($nodeId, $domain, $snapshot['state_revision'])
            ? json_encode(['node_id' => $nodeId, 'event' => $event, 'data' => $snapshot], JSON_THROW_ON_ERROR)
            : '';
        self::mirror($nodeId, $domain, $snapshot, $message);
    }

    public static function pushMachine(int $machineId, string $event, array $data): void
    {
        DB::afterCommit(fn () => self::publish(['machine_id' => $machineId, 'event' => $event, 'data' => $data]));
    }

    private static function publish(array $payload): bool
    {
        try {
            return (int) Redis::publish('node:push', json_encode($payload, JSON_THROW_ON_ERROR)) > 0;
        } catch (\Throwable $e) {
            Log::warning('[NodePush] Redis publish failed', ['event' => $payload['event'], 'error' => $e->getMessage()]);
            return false;
        }
    }
}
