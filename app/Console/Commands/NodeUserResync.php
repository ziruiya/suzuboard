<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Services\NodeSyncService;
use App\Services\ServerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * AUD-035: reconciliation for the fire-and-forget node user pushes.
 *
 * NodeSyncService marks every node whose user list may have changed in
 * a Redis set (see USERS_RESYNC_PENDING_KEY). This command, scheduled
 * every minute, re-pushes the full user list — always rebuilt from the
 * database — to each marked node that is currently online and clears
 * its mark. Offline nodes keep their mark; a reconnect already triggers
 * a full sync, and the next run after they return clears the rest.
 * Nodes that are gone or not machine-deliverable (disabled, or a Suzu
 * profile still outside production rollout) are dropped from the set
 * without receiving anything. Enterprise stock nodes ARE re-pushed:
 * the push only reaches the connection registered for that node, which
 * for a stock node can only be its own machine's agent.
 */
class NodeUserResync extends Command
{
    protected $signature = 'suzu:node-resync';

    protected $description = 'Re-push full user lists to nodes whose WS delta may have been missed (AUD-035)';

    public function handle(): int
    {
        try {
            $nodeIds = Redis::smembers(NodeSyncService::USERS_RESYNC_PENDING_KEY);
        } catch (\Throwable $e) {
            Log::warning("[NodeResync] Redis read failed: {$e->getMessage()}");
            $this->warn('redis unavailable, nothing to reconcile');
            return self::SUCCESS;
        }

        $pushed = 0;
        foreach ($nodeIds as $nodeId) {
            $nodeId = (int) $nodeId;
            $node = Server::find($nodeId);
            if (!$node || !ServerService::isMachineDeliverable($node)) {
                NodeSyncService::clearUsersResyncPending($nodeId);
                continue;
            }
            if (!NodeSyncService::isNodeOnline($nodeId)) {
                continue;
            }
            $users = ServerService::getAvailableUsers($node)->toArray();
            NodeSyncService::push($nodeId, 'sync.users', ['users' => $users]);
            NodeSyncService::clearUsersResyncPending($nodeId);
            $pushed++;
        }

        $this->info("node-resync: {$pushed} node(s) re-pushed, " . count($nodeIds) . ' pending total');
        return self::SUCCESS;
    }
}
