<?php

namespace App\Jobs;

use App\Models\EnterpriseInventoryAlert;
use App\Models\EnterpriseInventoryKit;
use App\Models\EnterpriseInventoryLog;
use App\Models\EnterpriseOrderIntent;
use App\Services\Enterprise\EnterpriseInventoryHealthService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Post-assignment delivery verification, dispatched after the paid()/open()
 * transaction commits: renders the subscription through the probe identity
 * and probes both kit nodes end to end. Success moves the intent to
 * DELIVERED; failure raises an ops alert (the customer keeps the lines —
 * verification failure is an ops problem, not a reason to roll back a
 * completed order).
 *
 * Verification is retried with increasing backoff over a bounded window:
 * right after assignment the agent may not have synced the kit's nodes
 * yet, and a single immediate probe misjudges that lag as a delivery
 * failure. Only when every attempt in the window has failed is the
 * delivery declared failed (fail-closed semantics unchanged).
 *
 * SB-ER-008: each queue execution performs exactly ONE bounded probe.
 * The previous shape slept through the whole backoff ladder (210s)
 * inside a single execution, but the production Horizon workers time
 * out at 30s — the process was killed mid-ladder, the retry restarted
 * the ladder from attempt 1, and the terminal alert could never fire.
 * Attempts now advance by re-dispatching the next attempt as a fresh
 * job with the ladder delay, so every execution fits the worker
 * timeout and the final attempt reliably raises the persistent alert.
 */
class EnterpriseDeliveryVerifyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $kitId,
        public int $intentId,
        public int $attempt = 1,
    ) {
    }

    public function handle(EnterpriseInventoryHealthService $health): void
    {
        $kit = EnterpriseInventoryKit::find($this->kitId);
        $intent = EnterpriseOrderIntent::find($this->intentId);
        if (!$kit || !$intent) {
            return;
        }
        $backoff = array_map('intval', (array) config('suzu_enterprise.delivery_verify_backoff_seconds', [20, 40, 60, 90]));
        $maxAttempts = count($backoff) + 1;

        $result = $health->verifyDelivery($kit);
        if ($result['ok']) {
            if ($intent->status === EnterpriseOrderIntent::STATUS_RESERVED) {
                $intent->status = EnterpriseOrderIntent::STATUS_DELIVERED;
                $intent->save();
            }
            return;
        }

        if ($this->attempt < $maxAttempts) {
            $delay = (int) ($backoff[$this->attempt - 1] ?? 0);
            EnterpriseInventoryLog::record(
                $kit->id,
                'delivery.verify.retry',
                "delivery verification attempt {$this->attempt}/{$maxAttempts} failed; retrying in {$delay}s (agent sync may lag)"
            );
            self::dispatch($this->kitId, $this->intentId, $this->attempt + 1)->delay($delay);
            return;
        }

        EnterpriseInventoryAlert::raise(
            $kit->purpose_id,
            $kit->id,
            EnterpriseInventoryAlert::TYPE_DELIVERY_VERIFY_FAILED,
            "Delivery verification failed for kit #{$kit->id} (order intent #{$intent->id})"
        );
    }
}
