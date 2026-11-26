<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * SB-TA-006: durable over-quota check event. One row per user whose
 * quota changed and who therefore needs an over-quota re-check by the
 * check:traffic-exceeded consumer. Written by TrafficFetchJob inside
 * the quota transaction (insertOrIgnore on the unique user_id), so
 * the signal can no longer be lost between the quota commit and a
 * volatile-store write.
 */
class TrafficPendingCheck extends Model
{
    protected $table = 'suzu_traffic_pending_checks';
    public $timestamps = false;
    protected $guarded = ['id'];

    /**
     * Record pending checks for the given users. Must be called
     * inside the transaction that applies the quota increments.
     */
    public static function recordForUsers(array $userIds): void
    {
        $now = time();
        $rows = [];
        foreach ($userIds as $userId) {
            $rows[] = ['user_id' => (int) $userId, 'created_at' => $now];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('suzu_traffic_pending_checks')->insertOrIgnore($chunk);
        }
    }
}
