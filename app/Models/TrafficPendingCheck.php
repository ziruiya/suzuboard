<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * SB-TA-006: durable over-quota check event. One row per user whose
 * quota changed and who therefore needs an over-quota re-check by
 * the check:traffic-exceeded consumer. Written by TrafficFetchJob
 * inside the quota transaction, so the signal can no longer be lost
 * between the quota commit and a volatile-store write.
 *
 * TA-006-R1: each row carries a monotonically increasing `version`.
 * recordForUsers() is an atomic upsert — a first booking inserts
 * version 1, a repeated booking bumps the version in the same
 * statement (row-locked by the unique key), so two concurrent
 * producer transactions can never mint the same version for two
 * distinct bookings. The consumer deletes a row only when its
 * version is still the version it read (see deleteConsumed()), so
 * an event re-booked while a check is running is never erased by
 * that check's cleanup; it is consumed by the next round instead.
 */
class TrafficPendingCheck extends Model
{
    protected $table = 'suzu_traffic_pending_checks';
    public $timestamps = false;
    protected $guarded = ['id'];

    /**
     * Record pending checks for the given users, advancing each
     * existing row's event version. Must be called inside the
     * transaction that applies the quota increments, so the version
     * bump commits or rolls back together with the increments.
     */
    public static function recordForUsers(array $userIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        if (empty($ids)) {
            return;
        }
        $now = time();
        $driver = DB::connection()->getDriverName();
        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '(?,?,1)'));
            $bindings = [];
            foreach ($chunk as $userId) {
                $bindings[] = $userId;
                $bindings[] = $now;
            }
            // Single-statement upsert per driver: the version bump
            // is part of the statement itself, so it is atomic under
            // the row lock the unique key provides — no read-then-
            // write window for concurrent producers to collide in.
            $sql = match ($driver) {
                'mysql' => "INSERT INTO suzu_traffic_pending_checks (user_id, created_at, version) VALUES {$placeholders} "
                    . 'ON DUPLICATE KEY UPDATE version = version + 1',
                'pgsql' => "INSERT INTO suzu_traffic_pending_checks (user_id, created_at, version) VALUES {$placeholders} "
                    . 'ON CONFLICT (user_id) DO UPDATE SET version = suzu_traffic_pending_checks.version + 1',
                'sqlite' => "INSERT INTO suzu_traffic_pending_checks (user_id, created_at, version) VALUES {$placeholders} "
                    . 'ON CONFLICT (user_id) DO UPDATE SET version = suzu_traffic_pending_checks.version + 1',
                default => null,
            };
            if ($sql !== null) {
                DB::statement($sql, $bindings);
                continue;
            }
            // Fallback for any other driver: row-locked read-modify-
            // write. The caller contract guarantees an enclosing
            // transaction, so the lock is held to commit.
            foreach ($chunk as $userId) {
                $existing = DB::table('suzu_traffic_pending_checks')
                    ->where('user_id', $userId)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    DB::table('suzu_traffic_pending_checks')
                        ->where('user_id', $userId)
                        ->update(['version' => (int) $existing->version + 1]);
                } else {
                    DB::table('suzu_traffic_pending_checks')
                        ->insertOrIgnore(['user_id' => $userId, 'created_at' => $now, 'version' => 1]);
                }
            }
        }
    }

    /**
     * Read the next chunk of pending events with their row IDs and versions, oldest first. The version is the consumer's claim on
     * exactly the events it is about to check.
     *
     * @return array<int, object{id:int, user_id:int, version:int}>
     */
    public static function nextChunk(int $limit = 1000, int $afterId = 0): array
    {
        return static::query()
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'user_id', 'version'])
            ->map(fn($row) => (object) [
                'id' => (int) $row->id,
                'user_id' => (int) $row->user_id,
                'version' => (int) $row->version,
            ])
            ->all();
    }

    /**
     * Delete consumed events, but ONLY the exact versions that were
     * read: a row whose version advanced since the read carries a
     * newer, still-unchecked event and must survive for the next
     * round. Returns the number of rows actually deleted.
     *
     * @param array<int, object{id:int, user_id:int, version:int}> $consumed
     */
    public static function deleteConsumed(array $consumed): int
    {
        $byVersion = [];
        foreach ($consumed as $row) {
            $byVersion[(int) $row->version][] = (int) $row->id;
        }
        $deleted = 0;
        foreach ($byVersion as $version => $ids) {
            $deleted += static::query()
                ->where('version', $version)
                ->whereIn('id', $ids)
                ->delete();
        }
        return $deleted;
    }
}
