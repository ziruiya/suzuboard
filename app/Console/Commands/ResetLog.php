<?php

namespace App\Console\Commands;

use App\Models\AdminAuditLog;
use App\Models\StatServer;
use App\Models\StatUser;
use Illuminate\Console\Command;

class ResetLog extends Command
{
    protected $builder;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset:log';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '清空日志';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        StatUser::where('record_at', '<', strtotime('-2 month', time()))->delete();
        StatServer::where('record_at', '<', strtotime('-2 month', time()))->delete();
        AdminAuditLog::where('created_at', '<', strtotime('-3 month', time()))->delete();
        // SB-AUD-022: prune traffic batch dedup markers after 48 hours;
        // realistic replays (agent retry, queue redelivery) land within
        // minutes, so the window only needs to cover those.
        if (\Illuminate\Support\Facades\Schema::hasTable('suzu_traffic_batches')) {
            \Illuminate\Support\Facades\DB::table('suzu_traffic_batches')
                ->where('created_at', '<', time() - 172800)
                ->delete();
        }
    }
}
