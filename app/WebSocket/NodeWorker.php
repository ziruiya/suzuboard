<?php

namespace App\WebSocket;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\DevicePushDelivery;
use App\Services\DeviceStateService;
use App\Services\MachineAuthEpoch;
use App\Services\NodeRegistry;
use App\Services\ServerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Workerman\Connection\TcpConnection;
use Workerman\Timer;
use Workerman\Worker;

class NodeWorker
{
    private const AUTH_TIMEOUT = 10;
    private const PING_INTERVAL = 55;

    public const HEARTBEAT_CACHE_KEY = 'ws_server:heartbeat';
    private const HEARTBEAT_INTERVAL = 10;
    private const HEARTBEAT_TTL = 30;

    private Worker $worker;

    private array $handlers = [
        'pong' => [NodeEventHandlers::class, 'handlePong'],
        'node.status' => [NodeEventHandlers::class, 'handleNodeStatus'],
        'report.devices' => [NodeEventHandlers::class, 'handleDeviceReport'],
        'request.devices' => [NodeEventHandlers::class, 'handleDeviceRequest'],
    ];

    public function __construct(string $host, int $port)
    {
        $this->worker = new Worker("websocket://{$host}:{$port}");
        $this->worker->count = 1;
        $this->worker->name = 'xboard-ws-server';
    }

    public function run(): void
    {
        $this->setupLogging();
        $this->setupCallbacks();
        Worker::runAll();
    }

    private function setupLogging(): void
    {
        $logPath = storage_path('logs');
        if (!is_dir($logPath)) {
            mkdir($logPath, 0777, true);
        }
        Worker::$logFile = $logPath . '/xboard-ws-server.log';
        Worker::$pidFile = $logPath . '/xboard-ws-server.pid';
    }

    private function setupCallbacks(): void
    {
        $this->worker->onWorkerStart = [$this, 'onWorkerStart'];
        $this->worker->onConnect = [$this, 'onConnect'];
        $this->worker->onWebSocketConnect = [$this, 'onWebSocketConnect'];
        $this->worker->onMessage = [$this, 'onMessage'];
        $this->worker->onClose = [$this, 'onClose'];
    }

    public function onWorkerStart(Worker $worker): void
    {
        Log::info("[WS] Worker started, pid={$worker->id}");
        $this->subscribeRedis();
        $this->setupTimers();
    }

    private function setupTimers(): void
    {
        Cache::put(self::HEARTBEAT_CACHE_KEY, time(), self::HEARTBEAT_TTL);
        Timer::add(self::HEARTBEAT_INTERVAL, function () {
            Cache::put(self::HEARTBEAT_CACHE_KEY, time(), self::HEARTBEAT_TTL);
        });

        Timer::add(self::PING_INTERVAL, function () {
            $seen = [];

            foreach (NodeRegistry::getConnectedNodeIds() as $nodeId) {
                $conn = NodeRegistry::get($nodeId);
                if ($conn) {
                    $oid = spl_object_id($conn);
                    if (!isset($seen[$oid])) {
                        $seen[$oid] = true;
                        if (MachineAuthEpoch::allows($conn)) {
                            $conn->send(json_encode(['event' => 'ping']));
                        }
                    }
                }
            }

            foreach (NodeRegistry::getConnectedMachineIds() as $machineId) {
                $conn = NodeRegistry::getMachine($machineId);
                if ($conn) {
                    $oid = spl_object_id($conn);
                    if (!isset($seen[$oid])) {
                        $seen[$oid] = true;
                        if (MachineAuthEpoch::allows($conn)) {
                            $conn->send(json_encode(['event' => 'ping']));
                        }
                    }
                }
            }
        });

        Timer::add(10, function () {
            $service = app(DeviceStateService::class);
            foreach (NodeRegistry::getConnectedNodeIds() as $nodeId) {
                try {
                    if (DevicePushDelivery::isPending($nodeId)) {
                        NodeEventHandlers::pushDeviceStateToNode($nodeId, $service);
                    }
                } catch (\Throwable $e) {
                    Log::warning("[WS] Device sync failed for node#{$nodeId}", ['error' => $e->getMessage()]);
                }
            }
        });
    }

    public function onConnect(TcpConnection $conn): void
    {
        $conn->authTimer = Timer::add(self::AUTH_TIMEOUT, function () use ($conn) {
            if (empty($conn->nodeId) && empty($conn->machineNodeIds)) {
                $conn->close(json_encode([
                    'event' => 'error',
                    'data' => ['message' => 'auth timeout'],
                ]));
            }
        }, [], false);
    }

    public function onWebSocketConnect(TcpConnection $conn, $httpMessage): void
    {
        $queryString = '';
        if (is_string($httpMessage)) {
            $queryString = parse_url($httpMessage, PHP_URL_QUERY) ?? '';
        } elseif ($httpMessage instanceof \Workerman\Protocols\Http\Request) {
            $queryString = $httpMessage->queryString();
        }

        parse_str($queryString, $params);

        if (isset($conn->authTimer)) {
            Timer::del($conn->authTimer);
        }

        // 判断认证模式
        if (!empty($params['machine_id'])) {
            $this->authenticateMachine($conn, $params);
        } else {
            $this->authenticateNode($conn, $params);
        }
    }

    /**
     * 旧模式：单节点认证
     */
    private function authenticateNode(TcpConnection $conn, array $params): void
    {
        $token = $params['token'] ?? '';
        $nodeId = (int) ($params['node_id'] ?? 0);

        $serverToken = admin_setting('server_token', '');
        if ($token === '' || $serverToken === '' || !hash_equals($serverToken, $token)) {
            $conn->close(json_encode([
                'event' => 'error',
                'data' => ['message' => 'invalid token'],
            ]));
            return;
        }

        $node = ServerService::getServer($nodeId, null);
        if (!$node) {
            $conn->close(json_encode([
                'event' => 'error',
                'data' => ['message' => 'node not found'],
            ]));
            return;
        }

        $conn->nodeId = $nodeId;
        NodeRegistry::add($nodeId, $conn);
        Cache::put("node_ws_alive:{$nodeId}", true, 86400);

        app(DeviceStateService::class)->clearAllNodeDevices($nodeId);

        Log::debug("[WS] Node#{$nodeId} connected", [
            'remote' => $conn->getRemoteIp(),
            'total' => NodeRegistry::count(),
        ]);

        $conn->onWebSocketConnected = function (TcpConnection $conn) use ($nodeId, $node) {
            $conn->send(json_encode([
                'event' => 'auth.success',
                'data' => ['node_id' => $nodeId],
            ]));
            NodeEventHandlers::pushFullSync($node);
        };
    }

    /**
     * 新模式：机器认证，自动注册该机器下所有已启用节点
     */
    private function authenticateMachine(TcpConnection $conn, array $params): void
    {
        $machineId = (int) ($params['machine_id'] ?? 0);
        $token = $params['token'] ?? '';

        $machine = ServerMachine::query()->useWritePdo()->where('id', $machineId)
            ->where('token', $token)
            ->first();

        if (!$machine || !$machine->is_active) {
            $conn->close(json_encode([
                'event' => 'error',
                'data' => ['message' => 'invalid machine credentials'],
            ]));
            return;
        }

        $conn->machineId = $machineId;
        $conn->machineAuthEpoch = $machine->auth_epoch;
        $conn->machineNodeIds = [];
        if (!MachineAuthEpoch::allows($conn)) {
            return;
        }

        $nodes = ServerService::getMachineNodes($machine);

        $machine->forceFill(['last_seen_at' => now()->timestamp])->saveQuietly();
        NodeRegistry::addMachine($machineId, $conn);

        // 把同一个连接注册到该机器下所有节点
        $nodeIds = [];
        $deviceService = app(DeviceStateService::class);
        foreach ($nodes as $node) {
            NodeRegistry::add($node->id, $conn);
            Cache::put("node_ws_alive:{$node->id}", true, 86400);
            $deviceService->clearAllNodeDevices($node->id);
            $nodeIds[] = $node->id;
        }

        // 连接上记录所属机器和节点列表
        $conn->machineNodeIds = $nodeIds;

        Log::debug("[WS] Machine#{$machineId} connected, nodes: " . implode(',', $nodeIds), [
            'remote' => $conn->getRemoteIp(),
            'total' => NodeRegistry::count(),
            'machines' => NodeRegistry::machineCount(),
        ]);

        $conn->onWebSocketConnected = function (TcpConnection $conn) use ($machineId, $nodeIds, $nodes) {
            if (!MachineAuthEpoch::allows($conn)) {
                return;
            }

            $conn->send(json_encode([
                'event' => 'auth.success',
                'data' => [
                    'machine_id' => $machineId,
                    'node_ids' => $nodeIds,
                ],
            ]));

            foreach ($nodes as $node) {
                NodeEventHandlers::pushFullSync($node);
            }
        };
    }

    public function onMessage(TcpConnection $conn, $data): void
    {
        $msg = json_decode($data, true);
        if (!is_array($msg)) {
            return;
        }

        $event = $msg['event'] ?? '';

        // 机器连接：从消息中读取 node_id 来分派到具体节点
        if (isset($conn->machineId)) {
            if (!MachineAuthEpoch::allows($conn)) {
                return;
            }

            if ($event === 'pong') {
                foreach ($conn->machineNodeIds as $nid) {
                    Cache::put("node_ws_alive:{$nid}", true, 86400);
                }
                return;
            }

            $nodeId = (int) ($msg['data']['node_id'] ?? 0);
            if ($nodeId <= 0 || !in_array($nodeId, $conn->machineNodeIds, true)) {
                return;
            }
            if (isset($this->handlers[$event])) {
                $handler = $this->handlers[$event];
                $handler($conn, $nodeId, $msg['data'] ?? []);
            }
            return;
        }

        // 旧模式：单节点
        $nodeId = $conn->nodeId ?? null;
        if (isset($this->handlers[$event]) && $nodeId) {
            $handler = $this->handlers[$event];
            $handler($conn, $nodeId, $msg['data'] ?? []);
        }
    }

    public function onClose(TcpConnection $conn): void
    {
        $service = app(DeviceStateService::class);

        // 机器模式：清理所有关联节点
        if (isset($conn->machineId)) {
            $machineId = $conn->machineId;
            NodeRegistry::removeMachine($machineId, $conn);
            foreach ($conn->machineNodeIds as $nodeId) {
                NodeRegistry::remove($nodeId, $conn);
            }

            foreach ($conn->machineNodeIds as $nodeId) {
                Cache::forget("node_ws_alive:{$nodeId}");

                $affectedUserIds = $service->clearAllNodeDevices($nodeId);
                foreach ($affectedUserIds as $userId) {
                    $service->notifyUpdate($userId);
                }
            }

            Log::debug("[WS] Machine#{$machineId} disconnected", [
                'nodes' => $conn->machineNodeIds,
                'total' => NodeRegistry::count(),
                'machines' => NodeRegistry::machineCount(),
            ]);
            return;
        }

        // 旧模式：单节点
        if (!empty($conn->nodeId)) {
            $nodeId = $conn->nodeId;
            NodeRegistry::remove($nodeId, $conn);
            Cache::forget("node_ws_alive:{$nodeId}");

            $affectedUserIds = $service->clearAllNodeDevices($nodeId);
            foreach ($affectedUserIds as $userId) {
                $service->notifyUpdate($userId);
            }

            Log::debug("[WS] Node#{$nodeId} disconnected", [
                'total' => NodeRegistry::count(),
                'affected_users' => count($affectedUserIds),
            ]);
        }
    }

    private function subscribeRedis(): void
    {
        $host = config('database.redis.default.host', '127.0.0.1');
        $port = config('database.redis.default.port', 6379);

        if (str_starts_with($host, '/')) {
            $redisUri = "unix://{$host}";
        } else {
            $redisUri = "redis://{$host}:{$port}";
        }

        $redis = new \Workerman\Redis\Client($redisUri);

        $password = config('database.redis.default.password');
        if ($password) {
            $redis->auth($password);
        }

        $prefix = config('database.redis.options.prefix', '');
        $channel = $prefix . 'node:push';

        $redis->subscribe([$channel], function ($chan, $message) {
            $this->onRedisPush($message);
        });

        Log::info("[WS] Subscribed to Redis channel: {$channel}");
    }

    public function onRedisPush(string $message): void
    {
        $payload = json_decode($message, true);
        if (!is_array($payload)) {
            return;
        }

        $event = $payload['event'] ?? '';
        $data = $payload['data'] ?? [];
        $machineId = (int) ($payload['machine_id'] ?? 0);
        if ($machineId && $event) {
            $conn = NodeRegistry::getMachine($machineId);
            if (!$conn) {
                return;
            }

            if ($event === MachineAuthEpoch::REVOKED_EVENT) {
                if (is_int($data['auth_epoch'] ?? null)) {
                    MachineAuthEpoch::revoke($conn, $data['auth_epoch']);
                }
                return;
            }

            $added = [];
            if ($event === 'sync.nodes') {
                if (!MachineAuthEpoch::allows($conn)) {
                    return;
                }
                $nodeIds = array_map('intval', array_column($data['nodes'] ?? [], 'id'));
                $added = array_diff($nodeIds, $conn->machineNodeIds ?? []);
                foreach (array_diff($conn->machineNodeIds ?? [], $nodeIds) as $removedId) {
                    unset(
                        $conn->stateRevisions["{$removedId}:config"], $conn->stateRevisions["{$removedId}:users"],
                        $conn->bufferedStateRevisions["{$removedId}:config"], $conn->bufferedStateRevisions["{$removedId}:users"],
                    );
                    Cache::forget("node_ws_alive:{$removedId}");
                }
                NodeRegistry::refreshMachineNodes($machineId, $nodeIds);
            }

            $sent = NodeRegistry::sendMachine($machineId, $event, $data);
            foreach ($added as $nodeId) {
                Cache::put("node_ws_alive:{$nodeId}", true, 86400);
                NodeEventHandlers::pushSnapshot($nodeId, 'sync.config');
                NodeEventHandlers::pushSnapshot($nodeId, 'sync.users');
            }
            Log::debug("[WS] {$event} to machine#{$machineId}", ['written' => $sent]);
            return;
        }

        $nodeId = (int) ($payload['node_id'] ?? 0);
        if ($nodeId && $event && NodeRegistry::get($nodeId)) {
            // Registry checks the epoch immediately before writing to the socket.
            $sent = NodeRegistry::send($nodeId, $event, $data);
            if (!$sent && $event === 'sync.user.delta'
                && ($data['state_revision'] ?? 0) > (NodeRegistry::get($nodeId)->stateRevisions["{$nodeId}:users"] ?? -1)) {
                NodeEventHandlers::pushSnapshot($nodeId, 'sync.users');
            }
            Log::debug("[WS] {$event} to node#{$nodeId}", ['written' => $sent]);
        }
    }
}
