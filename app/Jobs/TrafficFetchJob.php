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
    // SB-AUD-022: retries are safe now — the batch claim inside the
    // transaction makes processing idempotent, so a failed attempt can
    // be retried instead of silently dropping or double-counting.
    public $tries = 3;
    public $timeout = 20;

    public function backoff(): array
    {
        return [1, 5, 10];
    }

    public function __construct(array $server, array $data, $protocol, int $timestamp, ?string $batchHash = null)
    {
        $this->onQueue('traffic_fetch');
        $this->server = $server;
        $this->data = $data;
        $this->protocol = $protocol;
        $this->timestamp = $timestamp;
        $this->batchHash = $batchHash;
    }

    public function handle(): void
    {
        $data = $this->data;
        // Canonical lock order so concurrent batches touching the same
        // users queue on the row locks instead of deadlocking.
        ksort($data);
        $userIds = array_keys($data);

        $processed = DB::transaction(function () use ($data) {
            if ($this->batchHash !== null && !TrafficBatchGuard::claim(
                $this->batchHash,
                (int) ($this->server['id'] ?? 0),
                TrafficBatchGuard::PROCESSOR_FETCH
            )) {
                return false;
            }

            foreach ($data as $uid => $v) {
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
