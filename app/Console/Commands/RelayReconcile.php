<?php

namespace App\Console\Commands;

use App\Services\Relay\RelayReconcileService;
use Illuminate\Console\Command;

/**
 * SB-RELAY-01: five-minute relay reconciliation (drain completion, stale
 * apply detection, NODE-target eligibility drift). Scheduled in the
 * console kernel next to the other suzu:* jobs.
 */
class RelayReconcile extends Command
{
    protected $signature = 'suzu:relay-reconcile';

    protected $description = 'Reconcile SB-RELAY-01 machine/rule states against agent ACKs';

    public function handle(RelayReconcileService $service): int
    {
        $summary = $service->run();
        $this->info('relay reconcile: ' . json_encode($summary));
        return self::SUCCESS;
    }
}
