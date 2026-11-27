<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;

class DevicePushDelivery
{
    private const PENDING_KEY = 'device:push_pending_nodes';

    private static function key(int $nodeId): string
    {
        return "device:push_delivery:{$nodeId}";
    }

    public static function queue(int $nodeId, bool $reported = false): void
    {
        Redis::eval(<<<'LUA'
            local status = redis.call('HGET', KEYS[2], 'status')
            if ARGV[3] == '1' and status == 'awaiting_confirmation' then
                status = 'applied'
            elseif status ~= 'applied' then
                status = 'pending'
            end
            redis.call('HINCRBY', KEYS[2], 'generation', 1)
            redis.call('HSETNX', KEYS[2], 'attempts', 0)
            redis.call('HSET', KEYS[2], 'status', status, 'updated_at', ARGV[2])
            redis.call('SADD', KEYS[1], ARGV[1])
            LUA, 2, self::PENDING_KEY, self::key($nodeId), $nodeId, time(), $reported ? '1' : '0');
    }

    public static function retryUnconfirmed(int $nodeId): void
    {
        Redis::eval(<<<'LUA'
            if redis.call('HGET', KEYS[2], 'status') == 'awaiting_confirmation' then
                redis.call('HINCRBY', KEYS[2], 'generation', 1)
                redis.call('HSET', KEYS[2], 'status', 'pending', 'updated_at', ARGV[2])
                redis.call('SADD', KEYS[1], ARGV[1])
            end
            LUA, 2, self::PENDING_KEY, self::key($nodeId), $nodeId, time());
    }

    public static function isPending(int $nodeId): bool
    {
        return (bool) Redis::sismember(self::PENDING_KEY, $nodeId);
    }

    /**
     * Keep the set member until the socket confirms a write. A generation
     * change during the send leaves the newer snapshot queued.
     */
    public static function attempt(int $nodeId, callable $send): bool
    {
        $attempt = Redis::eval(<<<'LUA'
            if redis.call('SISMEMBER', KEYS[1], ARGV[1]) == 0 then
                return {}
            end
            redis.call('HINCRBY', KEYS[2], 'attempts', 1)
            redis.call('HSET', KEYS[2], 'status', 'pending', 'updated_at', ARGV[2])
            return {redis.call('HGET', KEYS[2], 'generation') or '0', redis.call('HGET', KEYS[2], 'attempts')}
            LUA, 2, self::PENDING_KEY, self::key($nodeId), $nodeId, time());

        if (!$attempt || !$send()) {
            return false;
        }

        Redis::eval(<<<'LUA'
            local generation = redis.call('HGET', KEYS[2], 'generation') or '0'
            if generation == ARGV[3] and redis.call('HGET', KEYS[2], 'attempts') == ARGV[4] then
                redis.call('HSET', KEYS[2], 'status', 'awaiting_confirmation', 'updated_at', ARGV[2])
                redis.call('SREM', KEYS[1], ARGV[1])
            end
            LUA, 2, self::PENDING_KEY, self::key($nodeId), $nodeId, time(), ...$attempt);

        return true;
    }

    public static function get(int $nodeId): ?array
    {
        $record = Redis::eval(<<<'LUA'
            local pending = redis.call('SISMEMBER', KEYS[1], ARGV[1])
            local fields = redis.call('HMGET', KEYS[2], 'status', 'updated_at', 'attempts')
            if not fields[1] and pending == 0 then
                return {}
            end
            return {fields[1] or 'pending', fields[2] or '', fields[3] or '0', pending}
            LUA, 2, self::PENDING_KEY, self::key($nodeId), $nodeId);

        if (!$record) {
            return null;
        }

        [$status, $updatedAt, $attempts, $pending] = $record;
        return [
            'status' => $status,
            'updated_at' => $updatedAt === '' ? null : (int) $updatedAt,
            'attempts' => (int) $attempts,
            'pending' => (bool) $pending,
        ];
    }
}
