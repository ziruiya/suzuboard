<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;

class DeviceStateService
{
    private const PREFIX = 'user_devices:';
    private const TTL = 300;                     // device state ttl
    private const DB_THROTTLE = 10;             // update db throttle

    /**
     * 移除 Redis key 的前缀
     */
    private function removeRedisPrefix(string $key): string
    {
        $prefix = config('database.redis.options.prefix', '');
        return $prefix ? substr($key, strlen($prefix)) : $key;
    }

    /**
     * Only alive_complete: true authorizes replacement of the entire node's
     * snapshot. Legacy reports refresh the reported IPs until their normal TTL.
     */
    public function reportDevices(int $nodeId, array $report, bool $complete): void
    {
        $devices = [];
        foreach ($report as $userId => $ips) {
            if (!ctype_digit((string) $userId) || !is_array($ips)
                || count(array_filter($ips, 'is_string')) !== count($ips)) {
                // A malformed snapshot must never authorize deletions.
                $complete = false;
                continue;
            }
            $devices[(int) $userId] = array_values(array_unique(array_map([self::class, 'normalizeIP'], $ips)));
        }

        if (!$complete && !$devices) {
            return;
        }

        $prefix = config('database.redis.options.prefix', '') . self::PREFIX;
        // Serialize complete and legacy writes in Redis. Fields for other
        // nodes in the shared per-user hashes are preserved.
        $affected = Redis::eval(<<<'LUA'
            local prefix = ARGV[1]
            local node = ARGV[2] .. ':'
            local devices = cjson.decode(ARGV[3])
            local affected = {}
            if ARGV[4] == '1' then
                for _, key in ipairs(redis.call('KEYS', prefix .. '*')) do
                    for _, field in ipairs(redis.call('HKEYS', key)) do
                        if string.sub(field, 1, #node) == node then
                            redis.call('HDEL', key, field)
                            affected[string.sub(key, #prefix + 1)] = true
                        end
                    end
                end
            end
            for uid, ips in pairs(devices) do
                local key = prefix .. uid
                for _, ip in ipairs(ips) do
                    redis.call('HSET', key, node .. ip, ARGV[5])
                end
                if #ips > 0 then
                    redis.call('EXPIRE', key, ARGV[6])
                end
                affected[uid] = true
            end
            local result = {}
            for uid, _ in pairs(affected) do
                table.insert(result, uid)
            end
            return result
            LUA, 0, $prefix, (string) $nodeId, json_encode((object) $devices, JSON_THROW_ON_ERROR),
            $complete ? '1' : '0', (string) time(), (string) self::TTL);

        foreach ($affected as $userId) {
            $this->notifyUpdate((int) $userId);
        }
    }

    /**
     * 获取某节点的所有设备数据
     * 返回: {userId: [ip1, ip2, ...], ...}
     */
    public function getNodeDevices(int $nodeId): array
    {
        $keys = Redis::keys(self::PREFIX . '*');
        $prefix = "{$nodeId}:";
        $result = [];
        foreach ($keys as $key) {
            $actualKey = $this->removeRedisPrefix($key);
            $uid = (int) substr($actualKey, strlen(self::PREFIX));
            $data = Redis::hgetall($actualKey);
            foreach ($data as $field => $timestamp) {
                if (str_starts_with($field, $prefix)) {
                    $ip = substr($field, strlen($prefix));
                    $result[$uid][] = $ip;
                }
            }
        }

        return $result;
    }

    /**
     * 删除某节点某用户的设备
     */
    public function removeNodeDevices(int $nodeId, int $userId): void
    {
        $key = self::PREFIX . $userId;
        $prefix = "{$nodeId}:";

        foreach (Redis::hkeys($key) as $field) {
            if (str_starts_with($field, $prefix)) {
                Redis::hdel($key, $field);
            }
        }
    }

    /**
     * 清除节点所有设备数据（用于节点断开连接）
     */
    public function clearAllNodeDevices(int $nodeId): array
    {
        $oldDevices = $this->getNodeDevices($nodeId);
        $prefix = "{$nodeId}:";

        foreach ($oldDevices as $userId => $ips) {
            $key = self::PREFIX . $userId;
            foreach (Redis::hkeys($key) as $field) {
                if (str_starts_with($field, $prefix)) {
                    Redis::hdel($key, $field);
                }
            }
            $this->notifyUpdate($userId);
        }

        return array_keys($oldDevices);
    }

    /**
     * get user device count (deduplicated by IP, filter expired data)
     */
    public function getDeviceCount(int $userId): int
    {
        $data = Redis::hgetall(self::PREFIX . $userId);
        $now = time();
        $ips = [];

        foreach ($data as $field => $timestamp) {
            if ($now - $timestamp <= self::TTL) {
                $ips[] = substr($field, strpos($field, ':') + 1);
            }
        }

        return count(array_unique($ips));
    }

    /**
     * get user device count (for alivelist interface)
     */
    public function getAliveList(Collection $users): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        $result = [];
        foreach ($users as $user) {
            $count = $this->getDeviceCount($user->id);
            if ($count > 0) {
                $result[$user->id] = $count;
            }
        }

        return $result;
    }

    /**
     * get devices of multiple users (for sync.devices, filter expired data)
     */
    public function getUsersDevices(array $userIds): array
    {
        $result = [];
        $now = time();
        foreach ($userIds as $userId) {
            $data = Redis::hgetall(self::PREFIX . $userId);
            if (!empty($data)) {
                $ips = [];
                foreach ($data as $field => $timestamp) {
                    if ($now - $timestamp <= self::TTL) {
                        $ips[] = substr($field, strpos($field, ':') + 1);
                    }
                }
                if (!empty($ips)) {
                    $result[$userId] = array_values(array_unique($ips));
                }
            }
        }

        return $result;
    }

    /**
     * Strip port from IP address: "1.2.3.4:12345" → "1.2.3.4", "[::1]:443" → "::1"
     */
    private static function normalizeIP(string $ip): string
    {
        // [IPv6]:port
        if (preg_match('/^\[(.+)\]:\d+$/', $ip, $m)) {
            return $m[1];
        }
        // IPv4:port
        if (preg_match('/^(\d+\.\d+\.\d+\.\d+):\d+$/', $ip, $m)) {
            return $m[1];
        }
        return $ip;
    }

    /**
     * notify update (throttle control)
     */
    public function notifyUpdate(int $userId): void
    {
        $dbThrottleKey = "device:db_throttle:{$userId}";

        // if (Redis::setnx($dbThrottleKey, 1)) {
        //     Redis::expire($dbThrottleKey, self::DB_THROTTLE);

            User::query()
                ->whereKey($userId)
                ->update([
                    'online_count' => $this->getDeviceCount($userId),
                    'last_online_at' => now(),
                ]);
        // }
    }
}
