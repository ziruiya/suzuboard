<?php

namespace App\Services\Enterprise;

use App\Models\EnterpriseInventoryConfig;
use App\Models\EnterpriseInventoryKit;
use App\Models\EnterpriseInventoryLog;
use Illuminate\Support\Facades\DB;

/**
 * State-machine transitions + bookkeeping shared by every enterprise
 * inventory flow. All transitions are validated against
 * EnterpriseInventoryKit::TRANSITIONS and written to the kit log.
 */
class EnterpriseInventoryService
{
    public function transition(EnterpriseInventoryKit $kit, string $to, string $event, ?string $detail = null, string $actorType = EnterpriseInventoryLog::ACTOR_SYSTEM, ?int $actorId = null): EnterpriseInventoryKit
    {
        if ($kit->status === $to) {
            return $kit;
        }
        if (!$kit->canTransitionTo($to)) {
            throw new \RuntimeException("Enterprise kit #{$kit->id} cannot transition {$kit->status} -> {$to}");
        }
        $from = $kit->status;
        $kit->status = $to;
        $kit->save();
        EnterpriseInventoryLog::record($kit->id, $event, $detail ?? "{$from} -> {$to}", $actorType, $actorId);
        return $kit;
    }

    /** Recount READY kits per purpose into the config row. */
    public function refreshLevel(int $purposeId): int
    {
        $ready = EnterpriseInventoryKit::where('purpose_id', $purposeId)
            ->where('status', EnterpriseInventoryKit::STATUS_READY)
            ->count();
        EnterpriseInventoryConfig::where('purpose_id', $purposeId)
            ->update(['current_level' => $ready]);
        return $ready;
    }

    public function targetLevel(int $purposeId): int
    {
        return (int) (EnterpriseInventoryConfig::where('purpose_id', $purposeId)->value('target_level') ?? EnterpriseInventoryConfig::DEFAULT_TARGET_LEVEL);
    }

    /** Kits that count toward the stock target (being built or ready to hand out). */
    public function activeStockCount(int $purposeId): int
    {
        return EnterpriseInventoryKit::where('purpose_id', $purposeId)
            ->whereIn('status', [
                EnterpriseInventoryKit::STATUS_BUILDING,
                EnterpriseInventoryKit::STATUS_VERIFYING,
                EnterpriseInventoryKit::STATUS_READY,
                EnterpriseInventoryKit::STATUS_HELD,
                EnterpriseInventoryKit::STATUS_RESERVED,
            ])
            ->count();
    }

    /** Next per-purpose sequence number (#001, #002, ...), row-locked. */
    public function nextSequence(int $purposeId): int
    {
        return DB::transaction(function () use ($purposeId) {
            $max = EnterpriseInventoryKit::where('purpose_id', $purposeId)
                ->lockForUpdate()
                ->max('sequence');
            return (int) $max + 1;
        });
    }
}
