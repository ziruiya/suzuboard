<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\TrafficBatchGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;
    protected $timestamp;
    protected $batchHash;
    protected $batchId;
    protected $contentHash;
    protected $collectedFrom;
    protected $collectedTo;
    // SB-AUD-022: retries are safe now — the batch claim inside the
    // transaction makes processing idempotent, so a failed attempt can
    // be retried instead of silently dropping or double-counting.
    public $tries = 3;
    public $timeout = 20;

    public function backoff(): array
    {
        return [1, 5, 10];
    }

    public function __construct(array $server, array $data, $protocol, int $timestamp, ?string $batchHash = null, ?string $batchId = null, ?string $contentHash = null, ?int $collectedFrom = null, ?int $collectedTo = null)
    {
        $this->onQueue('traffic_fetch');
        $this->server = $server;
        $this->data = $data;
        $this->protocol = $protocol;
        $this->timestamp = $timestamp;
        $this->batchHash = $batchHash;
        $this->batchId = $batchId;
        $this->contentHash = $contentHash;
        $this->collectedFrom = $collectedFrom;
        $this->collectedTo = $collectedTo;
    }

    public function handle(): void
    {
        $data = $this->data;
        // Canonical lock order so concurrent batches touching the same
        // users queue on the row locks instead of deadlocking.
        ksort($data);
        $userIds = array_keys($data);

        $processed = DB::transaction(function () use ($data, $userIds) {
            if (!TrafficBatchGuard::claimForProcessor(
                $this->batchHash,
                (int) ($this->server['id'] ?? 0),
                TrafficBatchGuard::PROCESSOR_FETCH,
                $this->batchId,
                $this->contentHash
            )) {
                return false;
            }

            // SB-TA-004: period attribution. A batch whose collection
            // window closed at or before the user's last traffic reset
            // belongs to the previous quota period and must not charge
            // the new one — regardless of when this job happens to run.
            // A window straddling the reset cannot be split (deltas are
            // window aggregates), so it is charged whole to the current
            // period and logged; see the batch-2 lab report.
            $skipUids = [];
            if ($this->collectedTo !== null && !empty($userIds)) {
                $resetAts = User::whereIn('id', $userIds)->pluck('last_reset_at', 'id');
                foreach ($userIds as $uid) {
                    $resetAt = (int) ($resetAts[$uid] ?? 0);
                    if ($resetAt > 0 && $this->collectedTo <= $resetAt) {
                        $skipUids[] = $uid;
                    } elseif ($resetAt > 0 && $this->collectedFrom !== null && $this->collectedFrom < $resetAt) {
                        \Illuminate\Support\Facades\Log::info('traffic batch straddles user reset; charged to current period', [
                            'server_id' => (int) ($this->server['id'] ?? 0),
                            'batch_id' => $this->batchId,
                            'user_id' => $uid,
                            'reset_at' => $resetAt,
                        ]);
                    }
                }
                if (!empty($skipUids)) {
                    \Illuminate\Support\Facades\Log::info('traffic batch belongs to previous quota period; skipped', [
                        'server_id' => (int) ($this->server['id'] ?? 0),
                        'batch_id' => $this->batchId,
                        'user_ids' => $skipUids,
                        'collected_to' => $this->collectedTo,
                    ]);
                }
            }

            foreach ($data as $uid => $v) {
                if (in_array($uid, $skipUids, true)) {
                    continue;
                }
                User::where('id', $uid)
                    ->incrementEach(
                        [
                            'u' => $v[0] * $this->server['rate'],
                            'd' => $v[1] * $this->server['rate'],
                        ],
                        ['t' => time()]
                    );
            }

            return true;
        });

        if ($processed && !empty($userIds)) {
            Redis::sadd('traffic:pending_check', ...$userIds);
        }
    }
}
