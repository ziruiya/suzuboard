<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * SB-AUD-022: exactly-once guard for node traffic batches.
 *
 * The UniProxy push payload has no batch identifier, so the panel
 * derives one from the batch content (node + protocol + the canonical
 * per-user deltas). Each processor claims the batch in
 * suzu_traffic_batches inside the same transaction as its writes:
 * the first claim processes, any replay (agent retry after an
 * ambiguous timeout, queue redelivery, job retry after a partial
 * failure) sees the marker and skips. If processing fails, the marker
 * rolls back with the writes and a retry can process the batch again.
 */
class TrafficBatchGuard
{
    public const PROCESSOR_FETCH = 'traffic_fetch';
    public const PROCESSOR_STAT_USER = 'stat_user';
    public const PROCESSOR_STAT_SERVER = 'stat_server';

    public static function hashFor(int $serverId, string $protocol, array $data): string
    {
        $normalized = [];
        foreach ($data as $uid => $v) {
            $normalized[(int) $uid] = [(float) ($v[0] ?? 0), (float) ($v[1] ?? 0)];
        }
        ksort($normalized);

        return hash('sha256', $serverId . '|' . $protocol . '|' . json_encode($normalized));
    }

    /**
     * Claim a batch for a processor. Returns true when this caller owns
     * processing; false when the batch was already claimed (replay).
     * Must be called inside the transaction that performs the writes.
     */
    public static function claim(string $batchHash, int $serverId, string $processor): bool
    {
        try {
            DB::table('suzu_traffic_batches')->insert([
                'batch_hash' => $batchHash,
                'server_id' => $serverId,
                'processor' => $processor,
                'created_at' => time(),
            ]);
            return true;
        } catch (QueryException $e) {
            $exists = DB::table('suzu_traffic_batches')
                ->where('batch_hash', $batchHash)
                ->where('processor', $processor)
                ->exists();
            if ($exists) {
                return false;
            }
            throw $e;
        }
    }
}
