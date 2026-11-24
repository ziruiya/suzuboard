<?php

namespace App\Services\Enterprise;

use App\Models\EnterpriseInventoryAlert;
use App\Models\EnterpriseInventoryKit;
use App\Models\EnterprisePurpose;

/**
 * Event-driven replenishment (D04): when a kit is claimed the stock drops
 * below target and exactly one replacement kit is started. There is no
 * in-system patrol timer; the 12h external patrol calls the same entry
 * point as a backstop.
 */
class EnterpriseReplenishmentService
{
    public function __construct(
        private EnterpriseInventoryService $inventory,
        private EnterpriseKitBuilderService $builder,
    ) {
    }

    public function replenishIfNeeded(EnterprisePurpose $purpose): ?EnterpriseInventoryKit
    {
        if (!$purpose->is_active) {
            return null;
        }
        if (!$purpose->blueprintComplete()) {
            EnterpriseInventoryAlert::raise($purpose->id, null, EnterpriseInventoryAlert::TYPE_BLUEPRINT_INCOMPLETE, "Purpose {$purpose->code} blueprint is incomplete; replenishment skipped");
            return null;
        }
        $target = $this->inventory->targetLevel($purpose->id);
        $ready = $this->inventory->refreshLevel($purpose->id);
        if ($ready >= $target) {
            return null;
        }
        if ($this->inventory->activeStockCount($purpose->id) >= $target) {
            return null; // a replacement is already being built/verified
        }
        try {
            $kit = $this->builder->buildKit($purpose, 'replenish');
        } catch (\Throwable $e) {
            EnterpriseInventoryAlert::raise($purpose->id, null, EnterpriseInventoryAlert::TYPE_STOCK_LOW, "Replenishment failed for {$purpose->code}: {$e->getMessage()}");
            return null;
        }
        $ready = $this->inventory->refreshLevel($purpose->id);
        if ($ready < $target) {
            EnterpriseInventoryAlert::raise($purpose->id, $kit->id, EnterpriseInventoryAlert::TYPE_STOCK_LOW, "Purpose {$purpose->code} READY level {$ready} below target {$target}");
        }
        return $kit;
    }

    /** @return array<int, EnterpriseInventoryKit|null> purpose_id => kit */
    public function replenishAll(): array
    {
        $result = [];
        foreach (EnterprisePurpose::where('is_active', true)->get() as $purpose) {
            $result[$purpose->id] = $this->replenishIfNeeded($purpose);
        }
        return $result;
    }
}
