<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\TrafficPendingCheck;
use App\Models\User;
use App\Services\NodeSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

class CheckTrafficExceeded extends Command
{
    protected $signature = 'check:traffic-exceeded';
    protected $description = '检查流量超标用户并通知节点';

    public function handle()
    {
        $this->drainLegacyChecks();

        $totalChecked = 0;
        $totalNotified = 0;
        $totalExceeded = 0;
        $totalDeferred = 0;
        $cursorKey = 'traffic:pending_check_cursor';
        $cursor = (int) Cache::get($cursorKey, 0);

        // Bound a run and resume after its last row so offline nodes do
        // not keep newer events outside the scan window indefinitely.
        for ($page = 0; $page < 5; $page++) {
            $pending = TrafficPendingCheck::nextChunk(1000, $cursor);
            if (!$pending) {
                $cursor = 0;
                break;
            }
            $cursor = end($pending)->id;
            $userIds = array_map(fn ($row) => $row->user_id, $pending);
            [$notified, $exceeded, $deferred] = $this->checkUsers($userIds);
            $totalChecked += count($userIds);
            $totalNotified += $notified;
            $totalExceeded += $exceeded;
            $totalDeferred += count($deferred);

            TrafficPendingCheck::deleteConsumed(array_filter($pending, fn ($row) => !isset($deferred[$row->user_id])));
            if (count($pending) < 1000) {
                $cursor = 0;
                break;
            }
        }
        Cache::put($cursorKey, $cursor, 3600);

        if ($totalChecked > 0) {
            $this->info("Checked {$totalChecked} users, notified {$totalNotified} nodes for {$totalExceeded} exceeded users. Deferred {$totalDeferred} checks.");
        }
    }

    private function drainLegacyChecks(): void
    {
        try {
            if (Redis::scard('traffic:pending_check') > 0) {
                $ids = (array) Redis::srandmember('traffic:pending_check', 1000);
                if ($ids) {
                    TrafficPendingCheck::recordForUsers($ids);
                    Redis::srem('traffic:pending_check', ...$ids);
                }
            }
        } catch (\Throwable $e) {
            $this->warn('Legacy Redis pending-check drain failed: ' . $e->getMessage());
        }
    }

    /**
     * Run the over-quota check for one set of users and push remove
     * deltas for the exceeded ones. Returns [notifiedCount,
     * exceededCount, deferredUserIds].
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
            return [0, 0, []];
        }

        $groupedUsers = $exceededUsers->groupBy('group_id');
        $notifiedCount = 0;
        $deferred = [];

        foreach ($groupedUsers as $groupId => $users) {
            if (!$groupId) {
                continue;
            }

            $userIdsInGroup = $users->pluck('id')->toArray();
            $servers = Server::whereJsonContains('group_ids', (string) $groupId)->get();

            foreach ($servers as $server) {
                if (NodeSyncService::push($server->id, 'sync.user.delta', [
                    'users' => array_map(fn ($id) => ['id' => $id], $userIdsInGroup),
                ])) {
                    $notifiedCount++;
                } else {
                    $deferred += array_fill_keys($userIdsInGroup, true);
                }
            }
        }

        return [$notifiedCount, $exceededUsers->count(), $deferred];
    }
}
