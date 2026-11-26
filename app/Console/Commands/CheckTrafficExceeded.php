<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\TrafficPendingCheck;
use App\Models\User;
use App\Services\NodeSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

class CheckTrafficExceeded extends Command
{
    protected $signature = 'check:traffic-exceeded';
    protected $description = '检查流量超标用户并通知节点';

    public function handle()
    {
        // SB-TA-006: pending checks are durable rows written by
        // TrafficFetchJob inside the quota transaction. Consume them
        // chunk-wise: process first, delete afterwards — a crash in
        // between only causes an idempotent re-check next run (the
        // node push below is a remove-delta, safe to repeat).
        $totalChecked = 0;
        $totalNotified = 0;
        $totalExceeded = 0;

        while (true) {
            $pendingUserIds = TrafficPendingCheck::query()
                ->orderBy('id')
                ->limit(1000)
                ->pluck('user_id')
                ->map(fn($id) => (int) $id)
                ->all();
            if (empty($pendingUserIds)) {
                break;
            }

            [$notified, $exceeded] = $this->checkUsers($pendingUserIds);
            $totalChecked += count($pendingUserIds);
            $totalNotified += $notified;
            $totalExceeded += $exceeded;

            TrafficPendingCheck::query()->whereIn('user_id', $pendingUserIds)->delete();
        }

        // Legacy drain: ids written to the Redis set by the pre-TA-006
        // producer may still be queued at cutover. Best-effort only —
        // a Redis failure must never block the durable path above.
        try {
            $count = Redis::scard('traffic:pending_check');
            if ($count > 0) {
                $legacyIds = array_map('intval', Redis::spop('traffic:pending_check', $count));
                if (!empty($legacyIds)) {
                    [$notified, $exceeded] = $this->checkUsers($legacyIds);
                    $totalChecked += count($legacyIds);
                    $totalNotified += $notified;
                    $totalExceeded += $exceeded;
                }
            }
        } catch (\Throwable $e) {
            $this->warn('Legacy Redis pending-check drain failed: ' . $e->getMessage());
        }

        if ($totalChecked > 0) {
            $this->info("Checked {$totalChecked} users, notified {$totalNotified} nodes for {$totalExceeded} exceeded users.");
        }
    }

    /**
     * Run the over-quota check for one set of users and push remove
     * deltas for the exceeded ones. Returns [notifiedCount,
     * exceededCount].
     */
    private function checkUsers(array $pendingUserIds): array
    {
        $exceededUsers = User::toBase()
            ->whereIn('id', $pendingUserIds)
            ->whereRaw('u + d >= transfer_enable')
            ->where('transfer_enable', '>', 0)
            ->where('banned', 0)
            ->select(['id', 'group_id'])
            ->get();

        if ($exceededUsers->isEmpty()) {
            return [0, 0];
        }

        $groupedUsers = $exceededUsers->groupBy('group_id');
        $notifiedCount = 0;

        foreach ($groupedUsers as $groupId => $users) {
            if (!$groupId) {
                continue;
            }

            $userIdsInGroup = $users->pluck('id')->toArray();
            $servers = Server::whereJsonContains('group_ids', (string) $groupId)->get();

            foreach ($servers as $server) {
                if (!NodeSyncService::isNodeOnline($server->id)) {
                    continue;
                }

                NodeSyncService::push($server->id, 'sync.user.delta', [
                    'action' => 'remove',
                    'users' => array_map(fn($id) => ['id' => $id], $userIdsInGroup),
                ]);
                $notifiedCount++;
            }
        }

        return [$notifiedCount, $exceededUsers->count()];
    }
}
