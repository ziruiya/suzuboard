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

    // SB-TA-001/002: outcomes of an identified-batch claim.
    public const CLAIM_OK = 'claimed';
    public const CLAIM_REPLAY = 'replay';
    public const CLAIM_CONFLICT = 'conflict';

    /**
     * Dedup key for a batch that carries a persistent agent-assigned
     * batch id (SB-TA-001/002). The key depends only on the node, the
     * batch id and the chunk sequence inside the batch — never on the
     * batch content — so a retry that re-sends the unacknowledged batch
     * unchanged hits the same key (001: no double count even when new
     * traffic arrived in the meantime, because new traffic travels in a
     * new batch), and two independent samples with identical content
     * carry different ids and therefore different keys (002: no silent
     * drop).
     */
    public static function keyForBatchId(int $serverId, string $batchId, int $seq): string
    {
        return hash('sha256', 'id|' . $serverId . '|' . $batchId . '|' . $seq);
    }

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

    /**
     * Unified claim used by all three batch processors. Returns true
     * when the caller owns processing. Identified batches (batchId !==
     * null) go through claimIdentified(); a content conflict is logged
     * here and reported as "do not process". Legacy batches keep the
     * AUD-022 content-hash claim untouched.
     */
    public static function claimForProcessor(?string $batchKey, int $serverId, string $processor, ?string $batchId, ?string $contentHash): bool
    {
        if ($batchKey === null) {
            return true;
        }
        if ($batchId === null) {
            return self::claim($batchKey, $serverId, $processor);
        }
        $status = self::claimIdentified($batchKey, $serverId, $processor, $contentHash);
        if ($status === self::CLAIM_CONFLICT) {
            \Illuminate\Support\Facades\Log::error('traffic batch conflict: same batch id, different content', [
                'server_id' => $serverId,
                'batch_id' => $batchId,
                'processor' => $processor,
            ]);
            return false;
        }
        return $status === self::CLAIM_OK;
    }

    /**
     * Claim an identified batch (SB-TA-001/002). Must be called inside
     * the transaction that performs the writes, exactly like claim().
     *
     * Returns CLAIM_OK when this caller owns processing, CLAIM_REPLAY
     * when the same (node, batch id, seq) was already processed with
     * the same content, and CLAIM_CONFLICT when the id was already
     * processed with DIFFERENT content — a protocol violation the
     * caller must refuse (and log), never fold into either batch.
     * The content fingerprint of the first claim is stored in the
     * content_hash column (migration 000008); rows claimed before that
     * column existed carry NULL and fall back to plain replay handling.
     */
    public static function claimIdentified(string $batchKey, int $serverId, string $processor, ?string $contentHash): string
    {
        $row = [
            'batch_hash' => $batchKey,
            'server_id' => $serverId,
            'processor' => $processor,
            'created_at' => time(),
        ];
        $hasContentHashColumn = \Illuminate\Support\Facades\Schema::hasColumn('suzu_traffic_batches', 'content_hash');
        if ($hasContentHashColumn) {
            $row['content_hash'] = $contentHash;
        }

        try {
            DB::table('suzu_traffic_batches')->insert($row);
            return self::CLAIM_OK;
        } catch (QueryException $e) {
            $existing = DB::table('suzu_traffic_batches')
                ->where('batch_hash', $batchKey)
                ->where('processor', $processor)
                ->first();
            if (!$existing) {
                throw $e;
            }
            $stored = $existing->content_hash ?? null;
            if ($stored !== null && $contentHash !== null && !hash_equals((string) $stored, $contentHash)) {
                return self::CLAIM_CONFLICT;
            }
            return self::CLAIM_REPLAY;
        }
    }
}
