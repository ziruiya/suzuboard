<?php

namespace App\Console\Commands;

use App\Models\EnterpriseInventoryKit;
use App\Services\Enterprise\EnterpriseInventoryHealthService;
use Illuminate\Console\Command;

class EnterpriseHealthCheck extends Command
{
    protected $signature = 'suzu:enterprise-health {--mode=light : light (content+TCP) or full (content+TCP+tunnel 204/exit)}';
    protected $description = 'Health-check READY enterprise stock kits (light every 6h, full daily)';

    public function handle(EnterpriseInventoryHealthService $health): int
    {
        $mode = $this->option('mode') === 'full' ? 'full' : 'light';
        $kits = EnterpriseInventoryKit::whereIn('status', [
            EnterpriseInventoryKit::STATUS_READY,
            EnterpriseInventoryKit::STATUS_VERIFYING,
        ])->get();
        $passed = 0;
        foreach ($kits as $kit) {
            $result = $health->verifyKit($kit, $mode);
            if ($result['ok']) {
                $passed++;
            }
        }
        $this->info("{$mode} checks: {$passed}/{$kits->count()} passed");
        return self::SUCCESS;
    }
}
