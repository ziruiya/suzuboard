<?php

namespace App\Services;

use App\Exceptions\TrafficBatchConflictException;
use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Jobs\TrafficFetchJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Transactional accounting claims and durable receipts for identified batches.
 * The compatibility path has only a content hash, which can also match an
 * independent sample. Retire it once remaining no-ID senders are removed.
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
    public const PROCESSOR_RECEIPT = 'batch_receipt';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_UNRECOVERABLE = 'unrecoverable';

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
     *
     * SB-TA-005: the claim insert must never raise on a uniqueness
     * conflict. The previous try-insert/catch-query shape breaks on
     * PostgreSQL: a unique violation aborts the surrounding
     * transaction, so the catch block's exists() probe runs inside a
     * failed transaction and throws SQLSTATE 25P02 instead of
     * reporting "already processed" — every replayed batch turned
     * into a failing, retrying job. insertOrIgnore compiles to
     * INSERT IGNORE (MySQL), INSERT ... ON CONFLICT DO NOTHING
     * (PostgreSQL) or INSERT OR IGNORE (SQLite): the conflict is
     * absorbed by the statement itself, the transaction stays usable,
     * and the affected-row count is the ownership verdict.
     */
    public static function claim(string $batchHash, int $serverId, string $processor): bool
    {
        $inserted = DB::table('suzu_traffic_batches')->insertOrIgnore([
            'batch_hash' => $batchHash,
            'server_id' => $serverId,
            'processor' => $processor,
            'created_at' => time(),
        ]);
        if ($inserted === 1) {
            return true;
        }
        // Ignored: a replay only if the marker row is really there.
        // Anything else (the row vanished in a prune race, or the
        // driver ignored the insert for a non-uniqueness reason) must
        // fail loudly so the job retries instead of silently dropping
        // the batch.
        $exists = DB::table('suzu_traffic_batches')
            ->where('batch_hash', $batchHash)
            ->where('processor', $processor)
            ->exists();
        if (!$exists) {
            throw new \RuntimeException('traffic batch claim: row vanished after ignored insert');
        }
        return false;
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
            $claimed = self::claim($batchKey, $serverId, $processor);
            if (!$claimed) {
                Log::warning('legacy traffic sample suppressed by content hash', [
                    'dedup_mode' => 'legacy_content_hash',
                    'server_id' => $serverId,
                    'batch_hash' => $batchKey,
                    'processor' => $processor,
                ]);
            }
            return $claimed;
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

        // SB-TA-005: conflict-tolerant insert (see claim()); the
        // follow-up read below is a plain SELECT in a healthy
        // transaction, never a probe inside an aborted one.
        $inserted = DB::table('suzu_traffic_batches')->insertOrIgnore($row);
        if ($inserted === 1) {
            return self::CLAIM_OK;
        }
        $existing = DB::table('suzu_traffic_batches')
            ->where('batch_hash', $batchKey)
            ->where('processor', $processor)
            ->first();
        if (!$existing) {
            // The insert was ignored but no row is visible: the row
            // vanished between the two statements (prune race). Fail
            // loudly so the job retries instead of silently treating
            // the batch as processed or unprocessed.
            throw new \RuntimeException('traffic batch claim: row vanished after ignored insert');
        }
        $stored = $existing->content_hash ?? null;
        if ($stored !== null && $contentHash !== null && !hash_equals((string) $stored, $contentHash)) {
            return self::CLAIM_CONFLICT;
        }
        return self::CLAIM_REPLAY;
    }

    public static function reserveReceipt(string $receiptHash, int $serverId, string $batchId, string $digest): bool
    {
        $table = DB::table('suzu_traffic_batches');
        $inserted = $table->insertOrIgnore([
            'batch_hash' => $receiptHash,
            'server_id' => $serverId,
            'processor' => self::PROCESSOR_RECEIPT,
            'content_hash' => $digest,
            'payload' => json_encode(['batch_id' => $batchId], JSON_THROW_ON_ERROR),
            'created_at' => time(),
        ]);
        if ($inserted === 1) {
            return true;
        }

        $stored = $table->where('batch_hash', $receiptHash)
            ->where('processor', self::PROCESSOR_RECEIPT)
            ->lockForUpdate()
            ->value('content_hash');
        if ($stored === null) {
            throw new \RuntimeException('traffic batch receipt reservation missing after ignored insert');
        }
        if (!hash_equals($stored, $digest)) {
            throw new TrafficBatchConflictException($serverId, $batchId);
        }
        return false;
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
    public static function receiveIdentified(string $batchKey, int $serverId, ?string $contentHash, string $payload, ?string $receiptHash = null): string
    {
        // SB-TA-005: conflict-tolerant insert (see claim()). A genuine
        // persistence failure still throws out of insertOrIgnore and
        // the caller surfaces it as an HTTP error, so the agent keeps
        // the batch — only the uniqueness replay is absorbed.
        $inserted = DB::table('suzu_traffic_batches')->insertOrIgnore([
            'batch_hash' => $batchKey,
            'server_id' => $serverId,
            'processor' => self::PROCESSOR_INBOX,
            'content_hash' => $contentHash,
            'payload' => $payload,
            'receipt_hash' => $receiptHash,
            'status' => self::STATUS_RECEIVED,
            'created_at' => time(),
        ]);
        if ($inserted === 1) {
            return self::RECEIPT_OK;
        }
        $existing = DB::table('suzu_traffic_batches')
            ->where('batch_hash', $batchKey)
            ->where('processor', self::PROCESSOR_INBOX)
            ->first();
        if (!$existing) {
            // Not a replay — the row vanished between the two
            // statements (prune race). Throw so the caller surfaces an
            // HTTP error and the agent keeps the batch.
            throw new \RuntimeException('traffic batch receipt: row vanished after ignored insert');
        }
        $stored = $existing->content_hash ?? null;
        if ($stored !== null && $contentHash !== null && !hash_equals((string) $stored, $contentHash)) {
            return self::RECEIPT_CONFLICT;
        }
        if ($receiptHash !== null && $existing->receipt_hash === null) {
            DB::table('suzu_traffic_batches')->where('id', $existing->id)->update(['receipt_hash' => $receiptHash]);
        }
        return self::RECEIPT_REPLAY;
    }

    public static function dispatchMissingProcessors(string $key, ?string $contentHash, array $payload): void
    {
        $completed = self::completedProcessors($key);
        $server = $payload['server'];
        $data = $payload['data'];
        $protocol = $payload['protocol'];
        $timestamp = $payload['timestamp'];
        $batchId = $payload['batch_id'] ?? $key; // Older inbox rows only carry the derived key.

        if (!in_array(self::PROCESSOR_FETCH, $completed, true)) {
            TrafficFetchJob::dispatch($server, $data, $protocol, $timestamp, $key, $batchId, $contentHash, $payload['collected_from'] ?? null, $payload['collected_to'] ?? null);
        }
        if (!in_array(self::PROCESSOR_STAT_USER, $completed, true)) {
            StatUserJob::dispatch($server, $data, $protocol, 'd', $key, $batchId, $contentHash, $timestamp);
        }
        if (!in_array(self::PROCESSOR_STAT_SERVER, $completed, true)) {
            StatServerJob::dispatch($server, $data, $protocol, 'd', $key, $batchId, $contentHash, $timestamp);
        }
    }

    private static function completedProcessors(string $key): array
    {
        return DB::table('suzu_traffic_batches')
            ->where('batch_hash', $key)
            ->whereIn('processor', [self::PROCESSOR_FETCH, self::PROCESSOR_STAT_USER, self::PROCESSOR_STAT_SERVER])
            ->pluck('processor')
            ->all();
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

        if (count(self::completedProcessors($batchKey)) < 3) {
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
