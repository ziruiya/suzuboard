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
        $complete = ($data['alive_complete'] ?? false) === true;
        $devices = $data['devices'] ?? array_diff_key($data, ['node_id' => true, 'alive_complete' => true]);
        if (is_array($devices)) {
            ServerService::processAlive($nodeId, $devices, $complete);
        }

        // A report confirms the previous write and queues the next snapshot.
        DevicePushDelivery::queue($nodeId, reported: true);
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

    /** A new connection has no confirmed baseline, so check both domains. */
    public static function pushFullSync(Server $node): void
    {
        $nodeId = (int) $node->id;
        try {
            DevicePushDelivery::retryUnconfirmed($nodeId);
        } catch (\Throwable $e) {
            Log::warning('[WS] Device delivery retry failed', ['node_id' => $nodeId, 'error' => $e->getMessage()]);
        }

        foreach (['sync.config', 'sync.users'] as $event) {
            self::pushSnapshot($nodeId, $event);
        }
    }

    public static function pushSnapshot(int $nodeId, string $event): void
    {
        try {
            if ($data = NodeSyncService::snapshot($nodeId, $event)) {
                NodeRegistry::send($nodeId, $event, $data);
            }
        } catch (\Throwable $e) {
            Log::warning('[WS] Node snapshot failed', ['node_id' => $nodeId, 'event' => $event, 'error' => $e->getMessage()]);
        }
    }
}
