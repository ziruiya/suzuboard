<?php

namespace App\Console\Commands;

use App\Services\Machine\MachineFulfillmentService;
use Illuminate\Console\Command;

/**
 * Business plans batch 3: time-driven dedicated-VPS fulfilment work —
 * paid sync, install detection + verification, SLA 90/150/180 stages,
 * expiry grace, reclaim promotion and vendor lease reminders.
 */
class MachineFulfillmentTick extends Command
{
    protected $signature = 'suzu:machine-fulfillment-tick';
    protected $description = 'Advance dedicated-VPS (business machine plan) fulfilments: install detection, SLA timers, grace and reclaim';

    public function handle(MachineFulfillmentService $service): int
    {
        $stats = $service->tick();
        $this->info('machine fulfilment tick: ' . json_encode($stats));
        return self::SUCCESS;
    }
}
