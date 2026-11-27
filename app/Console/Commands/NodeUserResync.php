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

    protected $description = 'Re-push pending full node configurations and user lists (AUD-035)';

    public function handle(): int
    {
        $this->resyncUsers();
        $this->resyncConfig();

        return self::SUCCESS;
    }

    private function resyncUsers(): void
    {
        try {
            $nodeIds = Redis::smembers(NodeSyncService::USERS_RESYNC_PENDING_KEY);
        } catch (\Throwable $e) {
            Log::warning("[NodeResync] Redis read failed: {$e->getMessage()}");
            $this->warn('redis unavailable, nothing to reconcile');
            return;
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
    }

    private function resyncConfig(): void
    {
        try {
            $pending = Redis::hgetall(NodeSyncService::CONFIG_RESYNC_PENDING_KEY);
            if ($pending === false) {
                throw new \RuntimeException('Redis rejected the pending config read');
            }
        } catch (\Throwable $e) {
            Log::warning("[NodeResync] Redis config read failed: {$e->getMessage()}");
            return;
        }

        foreach ($pending as $nodeId => $revision) {
            $nodeId = (int) $nodeId;
            $node = Server::find($nodeId);
            if (!$node || !ServerService::isMachineDeliverable($node)) {
                NodeSyncService::updateConfigResyncPending($nodeId, (int) $revision, true);
                continue;
            }
            if (NodeSyncService::isNodeOnline($nodeId)) {
                NodeSyncService::push($nodeId, 'sync.config', ['config' => ServerService::buildNodeConfig($node)]);
                // Only the worker can confirm the socket write and clear the mark.
            }
        }
    }
}
