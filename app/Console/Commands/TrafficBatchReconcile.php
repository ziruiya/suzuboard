<?php

namespace App\Console\Commands;

use App\Services\TrafficBatchGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class TrafficBatchReconcile extends Command
{
    protected $signature = 'suzu:traffic-batch-reconcile {--older-than=300 : Seconds a received batch may age before it is re-driven}';

    protected $description = 'Re-drive durably received traffic batches whose accounting jobs never completed';

    public function handle(): int
    {
        if (!Schema::hasTable('suzu_traffic_batches') || !Schema::hasColumn('suzu_traffic_batches', 'retry_at')) {
            $this->info('traffic batch reconcile: retry schedule migration is pending');
            return self::SUCCESS;
        }

        $now = time();
        $cutoff = $now - max(0, (int) $this->option('older-than'));
        $inbox = DB::table('suzu_traffic_batches')->where('processor', TrafficBatchGuard::PROCESSOR_INBOX);
        $pending = (clone $inbox)->where('status', TrafficBatchGuard::STATUS_RECEIVED);
        $rows = (clone $pending)
            ->where('created_at', '<=', $cutoff)
            ->where(fn ($query) => $query->whereNull('retry_at')->orWhere('retry_at', '<=', $now))
            ->orderByRaw('COALESCE(retry_at, created_at)')
            ->orderBy('id')
            ->limit(500)
            ->get();

        $summary = ['scanned' => $rows->count(), 'already_complete' => 0, 'redriven' => 0, 'errors' => 0, 'newly_isolated' => 0];
        foreach ($rows as $row) {
            $current = (clone $pending)->where('id', $row->id);
            try {
                if (TrafficBatchGuard::noteBatchProcessed($row->batch_hash, (int) $row->server_id)) {
                    $summary['already_complete']++;
                    continue;
                }

                $payload = json_decode((string) $row->payload, true);
                if (!$this->validPayload($payload)) {
                    $summary['newly_isolated'] += $current->update(['status' => TrafficBatchGuard::STATUS_UNRECOVERABLE]);
                    Log::error('traffic batch reconcile: isolated undecodable inbox payload', [
                        'batch_hash' => $row->batch_hash, 'server_id' => $row->server_id,
                    ]);
                    continue;
                }

                // Reserve the retry window before enqueueing. Competing
                // runs seeing the same snapshot cannot both take this row.
                $attempt = (int) $row->retry_count;
                $delay = min(21600, 300 * (2 ** min($attempt, 7)));
                $reserved = $current->where('retry_count', $attempt)->update([
                    'retry_at' => $now + $delay,
                    'retry_count' => $attempt + 1,
                ]);
                if ($reserved === 1) {
                    TrafficBatchGuard::dispatchMissingProcessors($row->batch_hash, $row->content_hash, $payload);
                    $summary['redriven']++;
                }
            } catch (\Throwable $e) {
                $summary['errors']++;
                Log::error('traffic batch reconcile: retry failed', [
                    'batch_hash' => $row->batch_hash, 'exception' => $e->getMessage(),
                ]);
            }
        }

        $summary['backlog'] = $pending->count();
        $summary['unrecoverable'] = (clone $inbox)->where('status', TrafficBatchGuard::STATUS_UNRECOVERABLE)->count();
        $this->info('traffic batch reconcile: ' . json_encode($summary));
        return $summary['errors'] || $summary['unrecoverable'] ? self::FAILURE : self::SUCCESS;
    }

    private function validPayload(mixed $payload): bool
    {
        return is_array($payload)
            && is_array($payload['data'] ?? null)
            && is_string($payload['protocol'] ?? null)
            && is_int($payload['timestamp'] ?? null)
            && is_array($payload['server'] ?? null)
            && isset($payload['server']['id'], $payload['server']['rate'])
            && is_numeric($payload['server']['rate'])
            && is_int($payload['collected_from'] ?? 0)
            && is_int($payload['collected_to'] ?? 0)
            && is_string($payload['batch_id'] ?? '');
    }
}
