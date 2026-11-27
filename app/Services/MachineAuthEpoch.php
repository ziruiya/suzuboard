<?php

namespace App\Services;

use App\Models\ServerMachine;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Workerman\Connection\TcpConnection;

class MachineAuthEpoch
{
    public const REVOKED_EVENT = 'machine.auth.revoked';

    public static function update(int $machineId, array $attributes): ServerMachine
    {
        $db = (new ServerMachine())->getConnection();

        return $db->transaction(function () use ($db, $machineId, $attributes) {
            $machine = ServerMachine::query()->lockForUpdate()->findOrFail($machineId);
            $machine->fill($attributes);
            $changed = array_key_exists('token', $attributes) || $machine->isDirty('is_active');

            if ($changed) {
                $machine->auth_epoch++;
            }
            if (!$machine->save()) {
                throw new \RuntimeException("Machine#{$machineId} update failed");
            }
            if ($changed) {
                $epoch = $machine->auth_epoch;
                $db->afterCommit(static fn () => self::publish($machineId, $epoch));
            }

            return $machine;
        });
    }

    private static function publish(int $machineId, int $epoch): void
    {
        try {
            // Concurrent after-commit callbacks may arrive in reverse order.
            $result = Redis::eval(<<<'LUA'
                local current = redis.call('GET', KEYS[1])
                if not current or #current < #ARGV[1] or (#current == #ARGV[1] and current < ARGV[1]) then
                    redis.call('SET', KEYS[1], ARGV[1])
                end
                return redis.call('PUBLISH', ARGV[2], ARGV[3])
                LUA, 1, "machine:auth_epoch:{$machineId}", (string) $epoch,
                config('database.redis.options.prefix', '') . 'node:push',
                json_encode([
                    'event' => self::REVOKED_EVENT,
                    'machine_id' => $machineId,
                    'data' => ['auth_epoch' => $epoch],
                ], JSON_THROW_ON_ERROR));
            if ($result === false) {
                throw new \RuntimeException('Redis rejected the epoch update');
            }
        } catch (\Throwable $e) {
            Log::warning('[WS] Machine authorization propagation failed', [
                'machine_id' => $machineId,
                'auth_epoch' => $epoch,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public static function allows(TcpConnection $conn): bool
    {
        $machineId = $conn->machineId ?? null;
        if ($machineId === null) {
            return true;
        }

        $epoch = $conn->machineAuthEpoch ?? null;
        $cached = null;
        try {
            $cached = Redis::get("machine:auth_epoch:{$machineId}");
        } catch (\Throwable $e) {
            Log::warning('[WS] Machine authorization cache read failed', ['machine_id' => $machineId]);
        }

        // A newer cache entry proves revocation. A matching entry could be stale
        // after a DB commit followed by a failed Redis write, so consult the primary.
        if ($epoch !== null && (int) $cached <= $epoch) {
            try {
                $machine = ServerMachine::query()->useWritePdo()->find($machineId, ['auth_epoch', 'is_active']);
                if ($machine?->is_active && $machine->auth_epoch === $epoch) {
                    return true;
                }
            } catch (\Throwable $e) {
                Log::warning('[WS] Machine authorization database read failed', ['machine_id' => $machineId]);
            }
        }

        // Discard already buffered frames as well.
        $conn->destroy();
        return false;
    }

    public static function revoke(TcpConnection $conn, int $epoch): void
    {
        if (($conn->machineAuthEpoch ?? -1) < $epoch) {
            $conn->destroy();
        }
    }
}
