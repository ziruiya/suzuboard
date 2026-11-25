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

    // SB-TA-B01: durable inbox for identified batches. One inbox row
    // per chunk (processor = 'inbox') is written synchronously at
    // report time and carries the validated payload plus a processing
    // status; the per-processor claim rows stay payload-free.
    public const PROCESSOR_INBOX = 'inbox';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_PROCESSED = 'processed';

    // SB-TA-B01: outcomes of a synchronous inbox receipt.
    public const RECEIPT_OK = 'received';
    public const RECEIPT_REPLAY = 'replay';
    public const RECEIPT_CONFLICT = 'conflict';

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

    /**
     * SB-TA-B01 protocol definition — durable receipt.
     *
     * For batches on the batch-id protocol, HTTP 200 from the report
     * endpoint means "the panel has DURABLY RECEIVED this batch", not
     * "the batch has been booked". Durable receipt is established here:
     * the validated chunk payload, its content fingerprint and a
     * 'received' status are inserted into suzu_traffic_batches
     * (processor = 'inbox') synchronously inside the report request,
     * before any accounting job is dispatched. Only when this insert
     * commits may the endpoint answer 200; any failure propagates and
     * the endpoint answers an HTTP error, so the agent keeps the batch
     * and re-sends it.
     *
     * Booking then happens asynchronously from this record: the three
     * processors claim the batch exactly as before (claim rows are the
     * idempotency mechanism) and noteBatchProcessed() flips the inbox
     * row to 'processed' once every processor's claim exists. A batch
     * whose jobs are lost (retries exhausted, queue failure) stays
     * 'received' with its payload intact, and the
     * suzu:traffic-batch-reconcile command re-drives it — booking is
     * recoverable and reconcilable, never dependent on queue retries
     * alone.
     *
     * The same-id conflict check also lives here, synchronously: a
     * re-sent batch id whose stored content hash differs is refused at
     * the HTTP layer (RECEIPT_CONFLICT), not acknowledged with 200 and
     * rejected later inside a job. A same-id same-content re-send is a
     * replay: the receipt already stands, no jobs are re-dispatched
     * (recovery of a stuck batch is the reconcile command's job).
     *
     * Legacy reports without a batch envelope have no durable identity
     * to receive against; they keep the AUD-022 queue path unchanged
     * and are outside this protocol.
     *
     * Returns RECEIPT_OK / RECEIPT_REPLAY / RECEIPT_CONFLICT. Throws on
     * any persistence failure that is not a uniqueness replay.
     */
    public static function receiveIdentified(string $batchKey, int $serverId, ?string $contentHash, string $payload): string
    {
        try {
            DB::table('suzu_traffic_batches')->insert([
                'batch_hash' => $batchKey,
                'server_id' => $serverId,
                'processor' => self::PROCESSOR_INBOX,
                'content_hash' => $contentHash,
                'payload' => $payload,
                'status' => self::STATUS_RECEIVED,
                'created_at' => time(),
            ]);
            return self::RECEIPT_OK;
        } catch (QueryException $e) {
            $existing = DB::table('suzu_traffic_batches')
                ->where('batch_hash', $batchKey)
                ->where('processor', self::PROCESSOR_INBOX)
                ->first();
            if (!$existing) {
                // Not a replay — a genuine receipt failure. The caller
                // must surface it as an HTTP error so the agent keeps
                // the batch.
                throw $e;
            }
            $stored = $existing->content_hash ?? null;
            if ($stored !== null && $contentHash !== null && !hash_equals((string) $stored, $contentHash)) {
                return self::RECEIPT_CONFLICT;
            }
            return self::RECEIPT_REPLAY;
        }
    }

    /**
     * SB-TA-B01: flip an inbox row to 'processed' once every accounting
     * processor (fetch / stat_user / stat_server) holds a claim row for
     * the batch. Called by each processor after its own transaction
     * commits, and by the reconcile command (a crash between the last
     * claim commit and this update must not strand the row). Returns
     * true when the inbox row is in 'processed' state afterwards.
     */
    public static function noteBatchProcessed(string $batchKey, int $serverId): bool
    {
        $inbox = DB::table('suzu_traffic_batches')
            ->where('batch_hash', $batchKey)
            ->where('processor', self::PROCESSOR_INBOX)
            ->first();
        if (!$inbox) {
            return false;
        }
        if (($inbox->status ?? null) === self::STATUS_PROCESSED) {
            return true;
        }

        $claimed = DB::table('suzu_traffic_batches')
            ->where('batch_hash', $batchKey)
            ->whereIn('processor', [self::PROCESSOR_FETCH, self::PROCESSOR_STAT_USER, self::PROCESSOR_STAT_SERVER])
            ->distinct()
            ->count('processor');
        if ($claimed < 3) {
            return false;
        }

        DB::table('suzu_traffic_batches')
            ->where('id', $inbox->id)
            ->update([
                'status' => self::STATUS_PROCESSED,
                'processed_at' => time(),
            ]);
        return true;
    }
}
