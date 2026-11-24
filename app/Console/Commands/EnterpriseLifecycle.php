<?php

namespace App\Console\Commands;

use App\Services\Enterprise\EnterpriseLifecycleService;
use App\Services\Enterprise\EnterpriseReplenishmentService;
use Illuminate\Console\Command;

class EnterpriseLifecycle extends Command
{
    protected $signature = 'suzu:enterprise-lifecycle';
    protected $description = 'Enterprise inventory lifecycle: hold timeouts, expiry grace, waiting queue drain and escalation';

    public function handle(EnterpriseLifecycleService $lifecycle, EnterpriseReplenishmentService $replenishment): int
    {
        $released = $lifecycle->releaseExpiredHolds();
        $graced = $lifecycle->expireAssignedKits();
        $quarantined = $lifecycle->quarantineStaleGraceKits();
        $replenishment->replenishAll();
        $drained = $lifecycle->drainWaitingIntents();
        $escalated = $lifecycle->escalateWaitingIntents();
        $this->info("holds released: {$released}, grace: {$graced}, quarantined: {$quarantined}, waiting assigned: {$drained}, escalated: {$escalated}");
        return self::SUCCESS;
    }
}
