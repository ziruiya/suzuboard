<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\NodeSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class NodeUserResync extends Command
{
    protected $signature = 'suzu:node-resync';

    protected $description = 'Reconcile delivered node configuration and user revisions';

    public function handle(): int
    {
        foreach (Server::query()->select('id')->lazyById() as $node) {
            if (!NodeSyncService::isNodeOnline($node->id)) {
                continue;
            }

            foreach (['sync.config', 'sync.users'] as $event) {
                try {
                    NodeSyncService::reconcile($node->id, $event);
                } catch (\Throwable $e) {
                    Log::warning('[NodeResync] Reconciliation failed', [
                        'node_id' => $node->id, 'event' => $event, 'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return self::SUCCESS;
    }
}
