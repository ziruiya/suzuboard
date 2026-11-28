<?php

namespace App\Services;

use App\Models\Server;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class NodeStateSnapshot
{
    public static function key(int $nodeId, string $domain): string
    {
        return "node:state_revision:{$nodeId}:{$domain}";
    }

    /**
     * Materialize committed state under the node row lock. The digest detects
     * changes; the revision is a separate DB-backed integer counter. Scanning
     * live state also recovers commits whose notification never ran.
     */
    public static function read(int $nodeId, string $domain, ?callable $allows = null): ?array
    {
        $connection = DB::connection();
        if (app('db.transactions')->callbackApplicableTransactions()->contains('connection', $connection->getName())) {
            throw new \LogicException('Node snapshots require a committed database view');
        }

        return $connection->transaction(function () use ($nodeId, $domain, $allows) {
            $node = Server::query()->lockForUpdate()->find($nodeId);
            if (!$node || !($allows ? $allows($node) : ServerService::isMachineDeliverable($node))) {
                return null;
            }

            $data = $domain === 'config'
                ? ['config' => self::config($node)]
                : ['users' => ServerService::getAvailableUsers($node)->sortBy('id')->values()
                    ->map(fn ($user) => $user instanceof \stdClass ? (array) $user : $user)->toArray()];
            $digest = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $key = self::key($nodeId, $domain);
            $record = DB::table('v2_settings')->where('name', $key);
            $value = $record->value('value');
            if ($value === null) {
                // Seed once from the pre-upgrade Redis counter. Advance for
                // the first snapshot since the legacy payload is unknown.
                $previous = self::legacyRevision($key);
                $oldDigest = null;
            } else {
                [$revision, $oldDigest] = explode(':', $value, 2);
                $previous = (int) $revision;
            }

            $revision = $previous;
            if ($oldDigest !== $digest) {
                $revision++;
                DB::table('v2_settings')->updateOrInsert(
                    ['name' => $key],
                    ['group' => Setting::NODE_REVISION_GROUP, 'value' => "{$revision}:{$digest}", 'updated_at' => now()],
                );
            }

            return [
                'data' => $data + ['state_revision' => $revision],
                'previous' => $previous,
                'previous_digest' => $oldDigest,
            ];
        }, 5);
    }

    private static function config(Server $node): array
    {
        // Read these alongside the node state, including from long-lived workers.
        $intervals = Setting::query()->whereIn('name', ['server_push_interval', 'server_pull_interval'])
            ->pluck('value', 'name');
        return ServerService::buildNodeConfig($node) + [
            'base_config' => [
                'push_interval' => (int) ($intervals['server_push_interval'] ?? config('v2board.server_push_interval') ?? 60),
                'pull_interval' => (int) ($intervals['server_pull_interval'] ?? config('v2board.server_pull_interval') ?? 60),
            ],
        ];
    }

    private static function legacyRevision(string $key): int
    {
        try {
            return (int) Redis::get($key);
        } catch (\Throwable $e) {
            // A failed read cannot distinguish a new node from a legacy
            // counter. Retry bootstrap when Redis is reachable.
            Log::warning('[NodePush] Legacy revision unavailable', ['key' => $key]);
            throw $e;
        }
    }
}
