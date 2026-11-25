<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerRoute;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;

class ServerService
{

    /**
     * 获取所有服务器列表
     * @return Collection
     */
    public static function getAllServers(): Collection
    {
        $query = Server::orderBy('sort', 'ASC');

        return $query->get()->append([
            'last_check_at',
            'last_push_at',
            'online',
            'is_online',
            'available_status',
            'cache_key',
            'load_status',
            'metrics',
            'online_conn'
        ]);
    }

    /**
     * 获取机器下所有已启用节点（仅含可向该机器 agent 下发的节点）
     *
     * 这里用的是机器口径（isMachineDeliverable）：库存节点必须出现在
     * 所属机器的清单里，agent 才会真正把落地跑起来；订阅不可见由
     * show=false 与 getAvailableServers 的 show 门单独保证，与下发无关。
     */
    public static function getMachineNodes(ServerMachine $machine): Collection
    {
        return Server::where('machine_id', $machine->id)
            ->where('enabled', true)
            ->orderBy('sort', 'ASC')
            ->get()
            ->filter(fn(Server $node) => self::isMachineDeliverable($node))
            ->values();
    }

    /** 节点是否为企业库存节点（suzu_enterprise_inventory_nodes 有登记）。 */
    public static function isEnterpriseInventoryNode(Server $node): bool
    {
        return \App\Models\EnterpriseInventoryNode::query()
            ->where('server_id', $node->id)
            ->exists();
    }

    /** 节点挂有 Suzu 协议档案且其 rollout_stage 未到 production。 */
    private static function hasNonProductionProfile(Server $node): bool
    {
        return \App\Models\SuzuNodeProfile::query()
            ->where('server_id', $node->id)
            ->where('rollout_stage', '!=', 'production')
            ->exists();
    }

    /**
     * 节点是否可向 agent 下发配置与用户列表（AUD-010 / AUD-011，严口径）。
     *
     * 三个条件缺一不可：
     * 1. 节点已启用（enabled）；
     * 2. 若节点挂有 Suzu 协议档案，其 rollout_stage 必须为 production；
     * 3. 企业库存节点在分配前不得下发：库存节点由建造器以 show=false 建入
     *    suzu_enterprise_inventory_nodes，分配时才翻 show=true、回收时翻回。
     *    非库存的订阅隐藏节点（如浏览器插件 http 节点）不在此列，保持原有下发。
     *
     * 本口径用于「无机器归属证明」的通道：全局 server_token（V1 中间件、
     * ServerV2 token 认证、WS 单节点认证经 getServer）与中转 NODE 目标
     * 资格——E11 的本意是未授权方拉不到库存节点，这条不变。
     */
    public static function isAgentDeliverable(Server $node): bool
    {
        if (!$node->enabled) {
            return false;
        }

        if (self::hasNonProductionProfile($node)) {
            return false;
        }

        if (!$node->show && self::isEnterpriseInventoryNode($node)) {
            return false;
        }

        return true;
    }

    /**
     * 节点是否可下发给「节点所属机器」的 agent（机器口径）。
     *
     * 与 isAgentDeliverable 的唯一差别：不应用库存 show 门。理由：
     * 机器通道以 machine token 认证且调用方已核验节点归属该机器
     * （ServerV2::authenticateByMachine / WS 机器认证 / getMachineNodes），
     * 不存在未授权拉取；库存节点若连配置都下发不了，agent 根本不会在
     * 落地端口监听，库存即为假库存（2026-11-25 实证：48 个库存节点
     * 全部未下发、落地端口无监听）。
     *
     * 库存节点的用户面收敛不靠这道门，而靠分组隔离：库存节点
     * group_ids 恒为其 kit 专属组（AUD-015），组内只有企业探针身份
     * （验活时临时入组）与分配后的客户，getAvailableUsers 按组取人
     * 天然只给出这两类。enabled 与 rollout 两道门与严口径一致保留。
     */
    public static function isMachineDeliverable(Server $node): bool
    {
        if (!$node->enabled) {
            return false;
        }

        if (self::hasNonProductionProfile($node)) {
            return false;
        }

        return true;
    }

    /**
     * 获取指定用户可用的服务器列表
     * @param User $user
     * @return array
     */
    public static function getAvailableServers(User $user): array
    {
        $servers = Server::whereJsonContains('group_ids', (string) $user->group_id)
            ->where('show', true)
            ->whereNotIn('id', \App\Models\SuzuNodeProfile::query()
                ->where('rollout_stage', '!=', 'production')
                ->pluck('server_id'))
            ->where(function ($query) {
                $query->whereNull('transfer_enable')
                    ->orWhere('transfer_enable', 0)
                    ->orWhereRaw('u + d < transfer_enable');
            })
            ->orderBy('sort', 'ASC')
            ->get()
            ->append(['last_check_at', 'last_push_at', 'online', 'is_online', 'available_status', 'cache_key', 'server_key']);

        $suzuProfiles = \App\Models\SuzuNodeProfile::whereIn('server_id', $servers->pluck('id'))
            ->get(['server_id', 'udp_enabled', 'profile_code'])->keyBy('server_id');
        $servers = collect($servers)->map(function ($server) use ($user, $suzuProfiles) {
            if ($profile = $suzuProfiles->get($server->id)) {
                $server->setAttribute('suzu_udp_enabled', (bool) $profile->udp_enabled);
                $server->setAttribute('suzu_profile_code', $profile->profile_code);
            }
            // 判断动态端口
            if (str_contains($server->port, '-')) {
                $port = $server->port;
                $server->port = (int) Helper::randomPort($port);
                $server->ports = $port;
            } else {
                $server->port = (int) $server->port;
            }
            $server->password = $server->generateServerPassword($user);
            $server->rate = $server->getCurrentRate();
            return $server;
        })->toArray();

        return $servers;
    }

    /**
     * 根据权限组获取可用的用户列表
     * @param array $groupIds
     * @return Collection
     */
    public static function getAvailableUsers(Server $node)
    {
        // 机器口径：库存节点要把 kit 组内用户（探针 + 已分配客户）交给
        // 所属机器的 agent，否则隧道无人可验、客户分配后也连不上。
        // 全局 token 通道在中间件层经 getServer() 严口径先行拦截，
        // 到不了这里；disabled / 非 production 档案节点在此仍返回空。
        if (!self::isMachineDeliverable($node)) {
            return collect();
        }
        $groupIds = $node->group_ids ?? [];
        if (empty($groupIds)) {
            return collect();
        }
        $users = User::toBase()
            ->whereIn('group_id', $groupIds)
            ->whereRaw('u + d < transfer_enable')
            ->where(function ($query) {
                $query->where('expired_at', '>=', time())
                    ->orWhere('expired_at', NULL);
            })
            ->where('banned', 0)
            ->select([
                'id',
                'uuid',
                'speed_limit',
                'device_limit'
            ])
            ->get();
        return HookManager::filter('server.users.get', $users, $node);
    }

    // 获取路由规则
    public static function getRoutes(array $routeIds)
    {
        $routes = ServerRoute::select(['id', 'match', 'action', 'action_value'])->whereIn('id', $routeIds)->get();
        return $routes;
    }

    /**
     * 处理节点流量数据汇报
     */
    public static function processTraffic(Server $node, array $traffic, ?array $batchMeta = null): void
    {
        $data = array_filter($traffic, fn($item) =>
            is_array($item) && count($item) === 2
            && is_numeric($item[0]) && is_numeric($item[1])
        );

        if (empty($data)) {
            return;
        }

        $nodeType = strtoupper($node->type);
        $nodeId = $node->id;

        Cache::put(CacheKey::get("SERVER_{$nodeType}_ONLINE_USER", $nodeId), count($data), 3600);
        Cache::put(CacheKey::get("SERVER_{$nodeType}_LAST_PUSH_AT", $nodeId), time(), 3600);

        // SB-TA-003 validation (bounds + group authorization) runs
        // centrally inside trafficFetch; the batch envelope
        // (SB-TA-001/002/004) is forwarded untouched.
        (new UserService())->trafficFetch($node, $node->type, $data, $batchMeta);
    }

    /**
     * 处理节点在线设备汇报
     */
    public static function processAlive(int $nodeId, array $alive): void
    {
        $service = app(DeviceStateService::class);
        foreach ($alive as $uid => $ips) {
            $service->setDevices((int) $uid, $nodeId, (array) $ips);
        }
    }

    /**
     * 处理节点连接数汇报
     */
    public static function processOnline(Server $node, array $online): void
    {
        $cacheTime = max(300, (int) admin_setting('server_push_interval', 60) * 3);
        $nodeType = $node->type;
        $nodeId = $node->id;

        foreach ($online as $uid => $conn) {
            $cacheKey = CacheKey::get("USER_ONLINE_CONN_{$nodeType}_{$nodeId}", $uid);
            Cache::put($cacheKey, (int) $conn, $cacheTime);
        }
    }

    /**
     * 处理节点负载状态汇报
     */
    public static function processStatus(Server $node, array $status): void
    {
        $nodeType = strtoupper($node->type);
        $nodeId = $node->id;

        $statusData = [
            'cpu' => (float) ($status['cpu'] ?? 0),
            'mem' => [
                'total' => (int) ($status['mem']['total'] ?? 0),
                'used' => (int) ($status['mem']['used'] ?? 0),
            ],
            'swap' => [
                'total' => (int) ($status['swap']['total'] ?? 0),
                'used' => (int) ($status['swap']['used'] ?? 0),
            ],
            'disk' => [
                'total' => (int) ($status['disk']['total'] ?? 0),
                'used' => (int) ($status['disk']['used'] ?? 0),
            ],
            'updated_at' => now()->timestamp,
            'kernel_status' => $status['kernel_status'] ?? null,
        ];

        $cacheTime = max(300, (int) admin_setting('server_push_interval', 60) * 3);
        cache([
            CacheKey::get("SERVER_{$nodeType}_LOAD_STATUS", $nodeId) => $statusData,
            CacheKey::get("SERVER_{$nodeType}_LAST_LOAD_AT", $nodeId) => now()->timestamp,
        ], $cacheTime);
    }

    /**
     * 标记节点心跳
     */
    public static function touchNode(Server $node): void
    {
        Cache::put(
            CacheKey::get('SERVER_' . strtoupper($node->type) . '_LAST_CHECK_AT', $node->id),
            time(),
            3600
        );
    }

    /**
     * Update node metrics and load status
     */
    public static function updateMetrics(Server $node, array $metrics): void
    {
        $nodeType = strtoupper($node->type);
        $nodeId = $node->id;
        $cacheTime = max(300, (int) admin_setting('server_push_interval', 60) * 3);

        $metricsData = [
            'uptime' => (int) ($metrics['uptime'] ?? 0),
            'goroutines' => (int) ($metrics['goroutines'] ?? 0),
            'active_connections' => (int) ($metrics['active_connections'] ?? 0),
            'total_connections' => (int) ($metrics['total_connections'] ?? 0),
            'total_users' => (int) ($metrics['total_users'] ?? 0),
            'active_users' => (int) ($metrics['active_users'] ?? 0),
            'inbound_speed' => (int) ($metrics['inbound_speed'] ?? 0),
            'outbound_speed' => (int) ($metrics['outbound_speed'] ?? 0),
            'cpu_per_core' => $metrics['cpu_per_core'] ?? [],
            'load' => $metrics['load'] ?? [],
            'speed_limiter' => $metrics['speed_limiter'] ?? [],
            'gc' => $metrics['gc'] ?? [],
            'api' => $metrics['api'] ?? [],
            'ws' => $metrics['ws'] ?? [],
            'limits' => $metrics['limits'] ?? [],
            'updated_at' => now()->timestamp,
            'kernel_status' => (bool) ($metrics['kernel_status'] ?? false),
        ];

        Cache::put(
            CacheKey::get('SERVER_' . $nodeType . '_METRICS', $nodeId),
            $metricsData,
            $cacheTime
        );
    }

    public static function buildNodeConfig(Server $node): array
    {
        $nodeType = $node->type;
        $protocolSettings = $node->protocol_settings;
        $serverPort = $node->server_port;
        $host = $node->host;

        $baseConfig = [
            'protocol' => $nodeType,
            'listen_ip' => '0.0.0.0',
            'server_port' => (int) $serverPort,
            'network' => data_get($protocolSettings, 'network'),
            'networkSettings' => data_get($protocolSettings, 'network_settings') ?: null,
        ];

        $response = match ($nodeType) {
            'shadowsocks' => [
                ...$baseConfig,
                'cipher' => $protocolSettings['cipher'],
                'plugin' => $protocolSettings['plugin'],
                'plugin_opts' => $protocolSettings['plugin_opts'],
                'server_key' => match ($protocolSettings['cipher']) {
                        '2022-blake3-aes-128-gcm' => Helper::getServerKey($node->created_at, 16),
                        '2022-blake3-aes-256-gcm' => Helper::getServerKey($node->created_at, 32),
                        default => null,
                    },
            ],
            'vmess' => [
                ...$baseConfig,
                'tls' => (int) $protocolSettings['tls'],
                'tls_settings' => $protocolSettings['tls_settings'],
                'multiplex' => data_get($protocolSettings, 'multiplex'),
            ],
            'trojan' => [
                ...$baseConfig,
                'host' => $host,
                'server_name' => data_get($protocolSettings, 'tls_settings.server_name'),
                'multiplex' => data_get($protocolSettings, 'multiplex'),
                'tls' => (int) $protocolSettings['tls'],
                'tls_settings' => match ((int) $protocolSettings['tls']) {
                        2 => $protocolSettings['reality_settings'],
                        default => $protocolSettings['tls_settings'],
                    },
            ],
            'vless' => [
                ...$baseConfig,
                'tls' => (int) $protocolSettings['tls'],
                'flow' => $protocolSettings['flow'],
                'decryption' => match (data_get($protocolSettings, 'encryption.enabled')) {
                    true => data_get($protocolSettings, 'encryption.decryption'),
                    default => null,
                },
                'tls_settings' => match ((int) $protocolSettings['tls']) {
                        2 => $protocolSettings['reality_settings'],
                        default => $protocolSettings['tls_settings'],
                    },
                'multiplex' => data_get($protocolSettings, 'multiplex'),
            ],
            'hysteria' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'version' => (int) $protocolSettings['version'],
                'host' => $host,
                'server_name' => $protocolSettings['tls']['server_name'],
                'tls_settings' => $protocolSettings['tls'],
                'up_mbps' => (int) $protocolSettings['bandwidth']['up'],
                'down_mbps' => (int) $protocolSettings['bandwidth']['down'],
                ...match ((int) $protocolSettings['version']) {
                        1 => ['obfs' => $protocolSettings['obfs']['password'] ?? null],
                        2 => [
                            'obfs' => $protocolSettings['obfs']['open'] ? $protocolSettings['obfs']['type'] : null,
                            'obfs-password' => $protocolSettings['obfs']['password'] ?? null,
                        ],
                        default => [],
                    },
            ],
            'tuic' => [
                ...$baseConfig,
                'version' => (int) $protocolSettings['version'],
                'server_port' => (int) $serverPort,
                'server_name' => $protocolSettings['tls']['server_name'],
                'congestion_control' => $protocolSettings['congestion_control'],
                'tls_settings' => $protocolSettings['tls'],
                'auth_timeout' => '3s',
                'zero_rtt_handshake' => false,
                'heartbeat' => '3s',
            ],
            'anytls' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'server_name' => $protocolSettings['tls']['server_name'],
                'tls_settings' => $protocolSettings['tls'],
                'padding_scheme' => $protocolSettings['padding_scheme'],
            ],
            'socks' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'tls' => (int) data_get($protocolSettings, 'tls', 0),
                'tls_settings' => data_get($protocolSettings, 'tls_settings'),
            ],
            'naive' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'tls' => (int) $protocolSettings['tls'],
                'tls_settings' => $protocolSettings['tls_settings'],
            ],
            'http' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'tls' => (int) $protocolSettings['tls'],
                'tls_settings' => $protocolSettings['tls_settings'],
            ],
            'mieru' => [
                ...$baseConfig,
                'server_port' => (int) $serverPort,
                'transport' => data_get($protocolSettings, 'transport', 'TCP'),
                'traffic_pattern' => $protocolSettings['traffic_pattern'],
            ],
            default => [],
        };

        if (!empty($node['route_ids'])) {
            $response['routes'] = self::getRoutes($node['route_ids']);
        }

        if (!empty($node['custom_outbounds'])) {
            $response['custom_outbounds'] = $node['custom_outbounds'];
        }

        if (!empty($node['custom_routes'])) {
            $response['custom_routes'] = $node['custom_routes'];
        }

        if (!empty($node['cert_config'])) {
            $certConfig = $node['cert_config'];
            // Normalize: accept both "mode" and "cert_mode" from the database
            if (isset($certConfig['mode']) && !isset($certConfig['cert_mode'])) {
                $certConfig['cert_mode'] = $certConfig['mode'];
                unset($certConfig['mode']);
            }
            if (data_get($certConfig, 'cert_mode') !== 'none') {
                $response['cert_config'] = $certConfig;
            }
        }

        return $response;
    }

    /**
     * 根据协议类型和标识获取服务器
     * @param int $serverId
     * @param string $serverType
     * @return Server|null
     */
    public static function getServer($serverId, ?string $serverType = null): Server | null
    {
        $server = Server::query()
            ->when($serverType, function ($query) use ($serverType) {
                $query->where('type', Server::normalizeType($serverType));
            })
            ->where(function ($query) use ($serverId) {
                $query->where('code', $serverId)
                    ->orWhere('id', $serverId);
            })
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [$serverId])
            ->first();

        if ($server && !self::isAgentDeliverable($server)) {
            return null;
        }

        return $server;
    }
}
