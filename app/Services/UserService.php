<?php

namespace App\Services;

use App\Jobs\StatServerJob;
use App\Jobs\StatUserJob;
use App\Jobs\TrafficFetchJob;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\TrafficResetService;
use App\Models\TrafficResetLog;
use App\Utils\Helper;
use Illuminate\Support\Facades\Hash;

class UserService
{
    /**
     * Get the remaining days until the next traffic reset for a user.
     * This method reuses the TrafficResetService logic for consistency.
     */
    public function getResetDay(User $user): ?int
    {
        // Use TrafficResetService to calculate the next reset time
        $trafficResetService = app(TrafficResetService::class);
        $nextResetTime = $trafficResetService->calculateNextResetTime($user);

        if (!$nextResetTime) {
            return null;
        }

        // Calculate the remaining days from now to the next reset time
        $now = time();
        $resetTimestamp = $nextResetTime->timestamp;

        if ($resetTimestamp <= $now) {
            return 0; // Reset time has passed or is now
        }

        // Calculate the difference in days (rounded up)
        $daysDifference = ceil(($resetTimestamp - $now) / 86400);

        return (int) $daysDifference;
    }

    public function isAvailable(User $user)
    {
        if (!$user->banned && $user->transfer_enable && ($user->expired_at > time() || $user->expired_at === NULL)) {
            return true;
        }
        return false;
    }

    public function getAvailableUsers()
    {
        return User::whereRaw('u + d < transfer_enable')
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                    ->orWhere('expired_at', NULL);
            })
            ->where('banned', 0)
            ->get();
    }

    public function getUnAvailbaleUsers()
    {
        return User::where(function ($query) {
            $query->where('expired_at', '<', time())
                ->orWhere('expired_at', 0);
        })
            ->where(function ($query) {
                $query->where('plan_id', NULL)
                    ->orWhere('transfer_enable', 0);
            })
            ->get();
    }

    public function getUsersByIds($ids)
    {
        return User::whereIn('id', $ids)->get();
    }

    public function getAllUsers()
    {
        return User::all();
    }

    public function addBalance(int $userId, int $balance): bool
    {
        $user = User::lockForUpdate()->find($userId);
        if (!$user) {
            return false;
        }
        $user->balance = $user->balance + $balance;
        if ($user->balance < 0) {
            return false;
        }
        if (!$user->save()) {
            return false;
        }
        return true;
    }

    public function isNotCompleteOrderByUserId(int $userId): bool
    {
        $order = Order::whereIn('status', [0, 1])
            ->where('user_id', $userId)
            ->first();
        if (!$order) {
            return false;
        }
        return true;
    }

    /** SB-TA-003: per-item delta sanity bound (1 PiB per user per batch). */
    public const MAX_TRAFFIC_DELTA = 1_000_000_000_000_000;

    /**
     * @param array|null $batchMeta SB-TA-001/002/004 batch envelope for
     *   agents that speak the persistent batch-id protocol:
     *   ['batch_id' => string, 'collected_from' => int, 'collected_to' => int].
     *   Null for legacy agents: the panel keeps the AUD-022 content-hash
     *   fallback, so old agents never lose accounting over this upgrade.
     */
    public function trafficFetch(Server $server, string $protocol, array $data, ?array $batchMeta = null)
    {
        $serverModel = $server;
        $server->rate = $server->getCurrentRate();
        $server = $server->toArray();

        list($server, $protocol, $data) = HookManager::filter('traffic.process.before', [$server, $protocol, $data]);
        // Compatible with legacy hook
        list($server, $protocol, $data) = HookManager::filter('traffic.before_process', [$server, $protocol, $data]);

        // SB-TA-003: every accounting entry point funnels through here
        // (UniProxy push, V2 report, Tidalab controllers), so validate
        // once, centrally: bounded non-negative integer deltas, and —
        // when the node declares permission groups — only users that
        // belong to one of the node's groups may be charged.
        $data = $this->validateTrafficData($serverModel, is_array($data) ? $data : []);
        if (empty($data)) {
            return;
        }

        $batchId = null;
        $collectedFrom = null;
        $collectedTo = null;
        if ($batchMeta !== null && isset($batchMeta['batch_id']) && is_string($batchMeta['batch_id']) && $batchMeta['batch_id'] !== '') {
            $batchId = substr($batchMeta['batch_id'], 0, 64);
            $collectedFrom = isset($batchMeta['collected_from']) ? (int) $batchMeta['collected_from'] : null;
            $collectedTo = isset($batchMeta['collected_to']) ? (int) $batchMeta['collected_to'] : null;
        }

        $timestamp = strtotime(date('Y-m-d'));
        collect($data)->chunk(1000)->each(function ($chunk, $seq) use ($timestamp, $server, $protocol, $batchId, $collectedFrom, $collectedTo) {
            $chunkData = $chunk->toArray();
            $contentHash = TrafficBatchGuard::hashFor((int) ($server['id'] ?? 0), $protocol, $chunkData);
            // SB-TA-001/002: identified batches dedup on (node, batch id,
            // chunk seq); the content hash rides along so a same-id
            // different-content replay is refused as a conflict.
            // Legacy batches keep the AUD-022 content-hash key.
            $batchHash = $batchId !== null
                ? TrafficBatchGuard::keyForBatchId((int) ($server['id'] ?? 0), $batchId, (int) $seq)
                : $contentHash;

            if ($batchId !== null) {
                // SB-TA-B01: durable receipt BEFORE any job is
                // dispatched. HTTP 200 for an identified batch means
                // "durably received" (payload + fingerprint + status
                // committed to the inbox), not "booked" — see
                // TrafficBatchGuard::receiveIdentified() for the full
                // protocol definition. A persistence failure throws
                // and the report endpoint answers an HTTP error, so
                // the agent keeps the batch; a same-id different-
                // content re-send is refused synchronously here.
                $payload = json_encode([
                    'protocol' => $protocol,
                    'timestamp' => $timestamp,
                    'server' => [
                        'id' => (int) ($server['id'] ?? 0),
                        'rate' => $server['rate'] ?? 1,
                        'type' => $server['type'] ?? null,
                    ],
                    'data' => $chunkData,
                    'collected_from' => $collectedFrom,
                    'collected_to' => $collectedTo,
                ]);
                $receipt = TrafficBatchGuard::receiveIdentified(
                    $batchHash,
                    (int) ($server['id'] ?? 0),
                    $contentHash,
                    $payload
                );
                if ($receipt === TrafficBatchGuard::RECEIPT_CONFLICT) {
                    \Illuminate\Support\Facades\Log::error('traffic batch conflict at receipt: same batch id, different content', [
                        'server_id' => (int) ($server['id'] ?? 0),
                        'batch_id' => $batchId,
                    ]);
                    throw new \App\Exceptions\TrafficBatchConflictException((int) ($server['id'] ?? 0), $batchId);
                }
                if ($receipt === TrafficBatchGuard::RECEIPT_REPLAY) {
                    // The durable receipt already stands. Do not
                    // re-dispatch: processors that completed are
                    // claimed, and a batch whose jobs were lost is
                    // re-driven by suzu:traffic-batch-reconcile.
                    return;
                }
            }

            TrafficFetchJob::dispatch($server, $chunkData, $protocol, $timestamp, $batchHash, $batchId, $contentHash, $collectedFrom, $collectedTo);
            StatUserJob::dispatch($server, $chunkData, $protocol, 'd', $batchHash, $batchId, $contentHash);
            StatServerJob::dispatch($server, $chunkData, $protocol, 'd', $batchHash, $batchId, $contentHash);
        });
    }

    /**
     * SB-TA-003 validation for one traffic batch.
     *
     * Authorization scope: the panel keeps no historical group-membership
     * snapshot, so membership is judged against the user's CURRENT group.
     * Node user lists are issued per group, so an out-of-group report is
     * either forged or cross-node confusion; the narrow window of traffic
     * collected just before a legitimate group change may be refused and
     * is logged (server, user, values) for manual reconciliation instead
     * of being silently dropped. Nodes with no declared groups keep the
     * legacy behaviour — there is no authorized set to judge against.
     */
    private function validateTrafficData(Server $server, array $data): array
    {
        $clean = [];
        $candidateIds = [];
        foreach ($data as $uid => $v) {
            $uid = (int) $uid;
            if (!is_array($v) || count($v) !== 2 || !is_numeric($v[0] ?? null) || !is_numeric($v[1] ?? null)) {
                \Illuminate\Support\Facades\Log::warning('traffic item rejected: malformed delta', [
                    'server_id' => $server->id, 'user_id' => $uid, 'value' => $v,
                ]);
                continue;
            }
            $up = $v[0] + 0;
            $down = $v[1] + 0;
            if ($up != floor($up) || $down != floor($down) || $up < 0 || $down < 0
                || $up > self::MAX_TRAFFIC_DELTA || $down > self::MAX_TRAFFIC_DELTA) {
                \Illuminate\Support\Facades\Log::warning('traffic item rejected: delta out of bounds', [
                    'server_id' => $server->id, 'user_id' => $uid, 'value' => $v,
                ]);
                continue;
            }
            $clean[$uid] = [(int) $up, (int) $down];
            $candidateIds[] = $uid;
        }

        $groupIds = $server->group_ids ?? [];
        if (!empty($groupIds) && !empty($candidateIds)) {
            $authorized = User::whereIn('id', $candidateIds)
                ->whereIn('group_id', $groupIds)
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->all();
            $authorizedSet = array_flip($authorized);
            foreach (array_keys($clean) as $uid) {
                if (!isset($authorizedSet[$uid])) {
                    \Illuminate\Support\Facades\Log::warning('traffic item rejected: user outside node groups', [
                        'server_id' => $server->id, 'user_id' => $uid, 'value' => $clean[$uid],
                    ]);
                    unset($clean[$uid]);
                }
            }
        }

        return $clean;
    }

    /**
     * 获取用户流量信息（增加重置检查）
     */
    public function getUserTrafficInfo(User $user): array
    {
        // 检查是否需要重置流量
        app(TrafficResetService::class)->checkAndReset($user, TrafficResetLog::SOURCE_USER_ACCESS);

        // 重新获取用户数据（可能已被重置）
        $user->refresh();

        return [
            'upload' => $user->u ?? 0,
            'download' => $user->d ?? 0,
            'total_used' => $user->getTotalUsedTraffic(),
            'total_available' => $user->transfer_enable ?? 0,
            'remaining' => $user->getRemainingTraffic(),
            'usage_percentage' => $user->getTrafficUsagePercentage(),
            'next_reset_at' => $user->next_reset_at,
            'last_reset_at' => $user->last_reset_at,
            'reset_count' => $user->reset_count,
        ];
    }

    /**
     * 创建用户
     */
    public function createUser(array $data): User
    {
        $user = new User();

        // 基本信息
        $user->email = $data['email'];
        $user->password = Hash::make($data['password'] ?? $user->email);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();

        // 默认设置
        $user->remind_expire = admin_setting('default_remind_expire', 1);
        $user->remind_traffic = admin_setting('default_remind_traffic', 1);
        $user->expired_at = null;

        // 可选字段
        $this->setOptionalFields($user, $data);

        // 处理计划
        if (isset($data['plan_id'])) {
            $this->setPlanForUser($user, $data['plan_id'], $data['expired_at'] ?? null);
        } else {
            $this->setTryOutPlan(user: $user);
        }

        return $user;
    }

    /**
     * 设置可选字段
     */
    private function setOptionalFields(User $user, array $data): void
    {
        $optionalFields = [
            'invite_user_id',
            'telegram_id',
            'group_id',
            'speed_limit',
            'expired_at',
            'transfer_enable'
        ];

        foreach ($optionalFields as $field) {
            if (array_key_exists($field, $data)) {
                $user->{$field} = $data[$field];
            }
        }
    }

    /**
     * 为用户设置计划
     */
    private function setPlanForUser(User $user, int $planId, ?int $expiredAt = null): void
    {
        $plan = Plan::find($planId);
        if (!$plan)
            return;

        $user->plan_id = $plan->id;
        $user->group_id = $plan->group_id;
        $user->transfer_enable = $plan->transfer_enable * 1073741824;
        $user->speed_limit = $plan->speed_limit;

        if ($expiredAt) {
            $user->expired_at = $expiredAt;
        }
    }

    /**
     * 为用户分配一个新套餐或续费现有套餐
     *
     * @param User $user 用户模型
     * @param Plan $plan 套餐模型
     * @param int $validityDays 购买天数
     * @return User 更新后的用户模型
     */
    public function assignPlan(User $user, Plan $plan, int $validityDays): User
    {
        $user->plan_id = $plan->id;
        $user->group_id = $plan->group_id;
        $user->transfer_enable = $plan->transfer_enable * 1073741824;
        $user->speed_limit = $plan->speed_limit;
        $user->device_limit = $plan->device_limit;

        if ($validityDays > 0) {
            $user = $this->extendSubscription($user, $validityDays);
        }

        $user->save();
        return $user;
    }

    /**
     * 延长用户的订阅有效期
     *
     * @param User $user 用户模型
     * @param int $days 延长天数
     * @return User 更新后的用户模型
     */
    public function extendSubscription(User $user, int $days): User
    {
        $currentExpired = $user->expired_at ?? time();
        $user->expired_at = max($currentExpired, time()) + ($days * 86400);

        return $user;
    }

    /**
     * 设置试用计划
     */
    private function setTryOutPlan(User $user): void
    {
        if (!(int) admin_setting('try_out_plan_id', 0))
            return;

        $plan = Plan::find(admin_setting('try_out_plan_id'));
        if (!$plan)
            return;

        $user->transfer_enable = $plan->transfer_enable * 1073741824;
        $user->plan_id = $plan->id;
        $user->group_id = $plan->group_id;
        $user->expired_at = time() + (admin_setting('try_out_hour', 1) * 3600);
        $user->speed_limit = $plan->speed_limit;
    }
}
