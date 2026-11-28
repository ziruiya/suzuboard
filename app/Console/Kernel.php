<?php

namespace App\Console;

use App\Services\Plugin\PluginManager;
use App\Utils\CacheKey;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        //
    ];

    /**
     * Define the application's command schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule): void
    {
        Cache::put(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null), time());
        // v2board
        $schedule->command('xboard:statistics')->dailyAt('0:10')->onOneServer();
        // check
        $schedule->command('check:order')->everyMinute()->onOneServer()->withoutOverlapping(5);
        $schedule->command('check:commission')->everyMinute()->onOneServer()->withoutOverlapping(5);
        $schedule->command('check:ticket')->everyMinute()->onOneServer()->withoutOverlapping(5);
        $schedule->command('check:traffic-exceeded')->everyMinute()->onOneServer()->withoutOverlapping(10)->runInBackground();
        // reset
        $schedule->command('reset:traffic')->everyMinute()->onOneServer()->withoutOverlapping(10);
        $schedule->command('reset:log')->daily()->onOneServer();
        // send
        $schedule->command('send:remindMail', ['--force'])->dailyAt('11:30')->onOneServer();
        // horizon metrics
        $schedule->command('horizon:snapshot')->everyFiveMinutes()->onOneServer();
        // cleanup stale online_count (GC for Redis TTL expiration)
        $schedule->command('cleanup:online-status')->everyFiveMinutes()->onOneServer();
        // SB-ENT-INV-01 enterprise inventory: lifecycle every 10 minutes
        // (hold timeouts, expiry grace, waiting-queue drain/escalation);
        // stock health: light probe every 6h, full probe daily.
        $schedule->command('suzu:enterprise-lifecycle')->everyTenMinutes()->onOneServer()->withoutOverlapping(10);
        $schedule->command('suzu:enterprise-health', ['--mode' => 'light'])->everySixHours()->onOneServer()->withoutOverlapping(30);
        $schedule->command('suzu:enterprise-health', ['--mode' => 'full'])->dailyAt('04:30')->onOneServer()->withoutOverlapping(60);
        // SB-RELAY-01: relay config reconciliation every 5 minutes
        $schedule->command('suzu:relay-reconcile')->everyFiveMinutes()->onOneServer()->withoutOverlapping(5);
        // SB-TA-B01: re-drive durably received traffic batches whose
        // accounting jobs never completed (durable-receipt protocol).
        $schedule->command('suzu:traffic-batch-reconcile')->everyFiveMinutes()->onOneServer()->withoutOverlapping(5);
        // AUD-035: re-push full user lists to nodes whose fire-and-forget
        // WS delta may have been missed while they were offline
        $schedule->command('suzu:node-resync')->everyMinute()->onOneServer()->withoutOverlapping(5);
        // Business plans batch 3: dedicated-VPS fulfilment tick every 5
        // minutes (install detection, SLA stages, grace/reclaim, leases)
        $schedule->command('suzu:machine-fulfillment-tick')->everyFiveMinutes()->onOneServer()->withoutOverlapping(5);
        $schedule->command('log0:maintain events')->everyMinute()->onOneServer()->withoutOverlapping(5)->runInBackground();
        $schedule->command('log0:maintain candidates')->everyMinute()->onOneServer()->withoutOverlapping(5)->runInBackground();
        $schedule->command('log0:maintain daily')->dailyAt('00:20')->timezone('Asia/Shanghai')
            ->onOneServer()->withoutOverlapping(30)->runInBackground();
        $schedule->command('log0:maintain retention')->dailyAt('00:40')->timezone('Asia/Shanghai')
            ->onOneServer()->withoutOverlapping(30)->runInBackground();
        // backup Timing
        // if (env('ENABLE_AUTO_BACKUP_AND_UPDATE', false)) {
        //     $schedule->command('backup:database', ['true'])->daily()->onOneServer();
        // }
        app(PluginManager::class)->registerPluginSchedules($schedule);

    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        try {
            app(PluginManager::class)->initializeEnabledPlugins();
        } catch (\Exception $e) {
        }
        require base_path('routes/console.php');
    }
}
