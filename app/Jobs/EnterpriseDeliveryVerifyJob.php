<?php

namespace App\Jobs;

use App\Models\EnterpriseInventoryAlert;
use App\Models\EnterpriseInventoryKit;
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
 */
class EnterpriseDeliveryVerifyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $kitId,
        public int $intentId,
    ) {
    }

    public function handle(EnterpriseInventoryHealthService $health): void
    {
        $kit = EnterpriseInventoryKit::find($this->kitId);
        $intent = EnterpriseOrderIntent::find($this->intentId);
        if (!$kit || !$intent) {
            return;
        }
        $result = $health->verifyDelivery($kit);
        if ($result['ok']) {
            if ($intent->status === EnterpriseOrderIntent::STATUS_RESERVED) {
                $intent->status = EnterpriseOrderIntent::STATUS_DELIVERED;
                $intent->save();
            }
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
