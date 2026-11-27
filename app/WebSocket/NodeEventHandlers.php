<?php

namespace App\WebSocket;

use App\Models\Server;
use App\Services\DevicePushDelivery;
use App\Services\DeviceStateService;
use App\Services\NodeRegistry;
use App\Services\NodeSyncService;
use App\Services\ServerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Workerman\Connection\TcpConnection;

class NodeEventHandlers
{
    /**
     * Handle pong heartbeat
     */
    public static function handlePong(TcpConnection $conn, int $nodeId, array $data = []): void
    {
        Cache::put("node_ws_alive:{$nodeId}", true, 86400);
    }

    /**
     * Handle node status update
     */
    public static function handleNodeStatus(TcpConnection $conn, int $nodeId, array $data): void
    {
        $node = Server::find($nodeId);
        if (!$node) return;

        $nodeType = strtoupper($node->type);
        Cache::put(\App\Utils\CacheKey::get('SERVER_' . $nodeType . '_LAST_CHECK_AT', $nodeId), time(), 3600);
        ServerService::updateMetrics($node, $data);

        Log::debug("[WS] Node#{$nodeId} status updated");
    }

    /**
     * Handle device report from node
     * 
     * 数据格式: {"event": "report.devices", "data": {userId: [ip1, ip2, ...], ...}}
     */
    public static function handleDeviceReport(TcpConnection $conn, int $nodeId, array $data): void
    {
        $service = app(DeviceStateService::class);

        if (isset($data['devices']) && is_array($data['devices'])) {
            $data = $data['devices'];
        }

        // Get old data
        $oldDevices = $service->getNodeDevices($nodeId);

        // Calculate diff
        $removedUsers = array_diff_key($oldDevices, $data);
        $newDevices = [];

        foreach ($data as $userId => $ips) {
            if (is_numeric($userId) && is_array($ips)) {
                $newDevices[(int) $userId] = $ips;
            }
        }

        // Handle removed users
        foreach ($removedUsers as $userId => $ips) {
            $service->removeNodeDevices($nodeId, $userId);
            $service->notifyUpdate($userId);
        }

        // Handle new/updated users
        foreach ($newDevices as $userId => $ips) {
            $service->setDevices($userId, $nodeId, $ips);
        }

        // A report confirms the previous write and queues the next snapshot.
        DevicePushDelivery::queue($nodeId, reported: true);

        Log::debug("[WS] Node#{$nodeId} synced " . count($newDevices) . " users, removed " . count($removedUsers));
    }

    /**
     * Handle device state request from node
     */
    public static function handleDeviceRequest(TcpConnection $conn, int $nodeId, array $data = []): void
    {
        DevicePushDelivery::queue($nodeId);
        self::pushDeviceStateToNode($nodeId, app(DeviceStateService::class));
    }

    /**
     * Push the latest pending device snapshot.
     */
    public static function pushDeviceStateToNode(int $nodeId, DeviceStateService $service): void
    {
        $sent = DevicePushDelivery::attempt($nodeId, function () use ($nodeId, $service) {
            $node = Server::find($nodeId);
            if (!$node) {
                return false;
            }

            $userIds = ServerService::getAvailableUsers($node)->pluck('id')->toArray();
            return NodeRegistry::send($nodeId, 'sync.devices', [
                'users' => $service->getUsersDevices($userIds),
            ]);
        });

        Log::debug("[WS] Device sync to node#{$nodeId}", ['written' => $sent]);
    }

    /**
     * Push full config + users to newly connected node.
     */
    public static function pushFullSync(Server $node): void
    {
        $nodeId = (int) $node->id;

        try {
            DevicePushDelivery::retryUnconfirmed($nodeId);
            $configRevision = NodeSyncService::currentRevision($nodeId, 'sync.config');
            $configSent = NodeRegistry::send($nodeId, 'sync.config', [
                'config' => ServerService::buildNodeConfig($node),
                'state_revision' => $configRevision,
            ]);

            $usersRevision = NodeSyncService::currentRevision($nodeId, 'sync.users');
            $users = ServerService::getAvailableUsers($node)->toArray();
            $usersSent = NodeRegistry::send($nodeId, 'sync.users', [
                'users' => $users,
                'state_revision' => $usersRevision,
            ]);

            if (!$usersSent) {
                NodeSyncService::markUsersResyncPending($nodeId);
            }

            Log::info("[WS] Initial sync to node#{$nodeId}", [
                'config_written' => $configSent,
                'users_written' => $usersSent,
                'users' => count($users),
            ]);
        } catch (\Throwable $e) {
            Log::warning("[WS] Initial sync failed for node#{$nodeId}", ['error' => $e->getMessage()]);
        }
    }
}
