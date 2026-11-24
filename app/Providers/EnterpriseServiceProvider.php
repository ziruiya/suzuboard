<?php

namespace App\Providers;

use App\Services\Enterprise\CloudflareEnterpriseDnsService;
use App\Services\Enterprise\EnterpriseDnsService;
use App\Services\Enterprise\KitProbe;
use App\Services\Enterprise\SingBoxKitProbe;
use App\Services\Machine\MachineNodeProbe;
use App\Services\Machine\TcpMachineNodeProbe;
use Illuminate\Support\ServiceProvider;

/**
 * SB-ENT-INV-01 bindings: the probe edge and DNS driver sit behind
 * interfaces so tests (and future gost-based automation) can swap them.
 */
class EnterpriseServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind(KitProbe::class, SingBoxKitProbe::class);
        $this->app->bind(EnterpriseDnsService::class, CloudflareEnterpriseDnsService::class);
        // Business plans batch 3: dedicated-VPS delivery verification edge.
        $this->app->bind(MachineNodeProbe::class, TcpMachineNodeProbe::class);
    }

    public function boot()
    {
    }
}
