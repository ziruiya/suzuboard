<?php

namespace App\Console\Commands;

use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Jobs\TrafficFetchJob;
use App\Services\TrafficBatchGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * SB-TA-B01: recovery path for the durable traffic-batch inbox.
 *
 * HTTP 200 on the report endpoint means "durably received", not
 * "booked" (see TrafficBatchGuard::receiveIdentified). Booking is
 * driven by the three accounting jobs; if they are lost — retries
 * exhausted, queue failure, worker killed between receipt and
 * dispatch — the inbox row stays in 'received' status WITH its
 * payload, and this command re-drives it. Re-driving is idempotent:
 * processors that already booked the batch hold claim rows and skip,
 * the missing ones process it exactly once.
 *
 * A row is only re-driven after it has been 'received' for
 * --older-than seconds, so batches whose jobs are merely queued or
 * in flight are left alone. An unprocessed inbox row AND all claim
 * rows of its batch family are exempt from the reset:log 48-hour
 * prune (SB-TA-B01-R1 / R1-C1), so a batch stranded by an outage
 * longer than that window is still recoverable here — with its
 * claims intact, so recovery never double-books; only processed
 * inbox rows and the claim markers of finished families are pruned
 * by age.
 *
 * Scheduled in the console kernel next to the other suzu:* jobs.
 */
class TrafficBatchReconcile extends Command
{
    protected $signature = 'suzu:traffic-batch-reconcile {--older-than=300 : Seconds a received batch may age before it is re-driven}';

    protected $description = 'Re-drive durably received traffic batches whose accounting jobs never completed (SB-TA-B01)';

    public function handle(): int
    {
        if (!Schema::hasTable('suzu_traffic_batches')
            || !Schema::hasColumn('suzu_traffic_batches', 'payload')
            || !Schema::hasColumn('suzu_traffic_batches', 'status')) {
            $this->info('traffic batch reconcile: inbox columns not present (migration 000010 not applied), nothing to do');
            return self::SUCCESS;
        }

        $olderThan = max(0, (int) $this->option('older-than'));
        $cutoff = time() - $olderThan;

        $rows = DB::table('suzu_traffic_batches')
            ->where('processor', TrafficBatchGuard::PROCESSOR_INBOX)
            ->where('status', TrafficBatchGuard::STATUS_RECEIVED)
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit(500)
            ->get();

        $summary = ['scanned' => 0, 'already_complete' => 0, 'redriven' => 0, 'errors' => 0];

        foreach ($rows as $row) {
            $summary['scanned']++;

            // A crash between the last processor's claim commit and
            // its inbox update leaves a fully booked batch marked
            // 'received'; completing the bookkeeping is enough.
            if (TrafficBatchGuard::noteBatchProcessed($row->batch_hash, (int) $row->server_id)) {
                $summary['already_complete']++;
                continue;
            }

            $payload = json_decode((string) ($row->payload ?? ''), true);
            if (!is_array($payload) || !isset($payload['data'], $payload['protocol'], $payload['timestamp'])) {
                Log::error('traffic batch reconcile: inbox payload undecodable, leaving row for manual inspection', [
                    'batch_hash' => $row->batch_hash,
                    'server_id' => (int) $row->server_id,
                ]);
                $summary['errors']++;
                continue;
            }

            $server = [
                'id' => (int) ($payload['server']['id'] ?? $row->server_id),
                'rate' => $payload['server']['rate'] ?? 1,
                'type' => $payload['server']['type'] ?? null,
            ];
            $data = $payload['data'];
            $protocol = (string) $payload['protocol'];
            $timestamp = (int) $payload['timestamp'];
            $contentHash = $row->content_hash !== null ? (string) $row->content_hash : null;
            // The batch id itself is not stored on the inbox row (the
            // claim key is derived from it); the jobs only need a
            // non-null batch id to engage the identified-batch claim
            // path, and the key they claim under is the row's
            // batch_hash, so the derived key stays identical.
            $batchId = $row->batch_hash;

            TrafficFetchJob::dispatch($server, $data, $protocol, $timestamp, $row->batch_hash, $batchId, $contentHash, $payload['collected_from'] ?? null, $payload['collected_to'] ?? null);
            // SB-TA-007: a re-driven batch keeps its ORIGINAL receipt
            // timestamp as the stat bucket, so a batch received before
            // midnight and recovered after it still lands in the
            // receipt day's stats.
            StatUserJob::dispatch($server, $data, $protocol, 'd', $row->batch_hash, $batchId, $contentHash, $timestamp);
            StatServerJob::dispatch($server, $data, $protocol, 'd', $row->batch_hash, $batchId, $contentHash, $timestamp);
            $summary['redriven']++;
        }

        $this->info('traffic batch reconcile: ' . json_encode($summary));
        return self::SUCCESS;
    }
}
