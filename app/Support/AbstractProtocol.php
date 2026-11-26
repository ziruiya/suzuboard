<?php

namespace App\Support;

use App\Services\Plugin\HookManager;

abstract class AbstractProtocol
{
    /**
     * @var array 用户信息
     */
    protected $user;

    /**
     * @var array 服务器信息
     */
    protected $servers;

    /**
     * @var string|null 客户端名称
     */
    protected $clientName;

    /**
     * @var string|null 客户端版本
     */
    protected $clientVersion;

    /**
     * @var string|null 原始 User-Agent
     */
    protected $userAgent;

    /**
     * @var array 协议标识
     */
    public $flags = [];

    /**
     * @var array 协议需求配置
     */
    protected $protocolRequirements = [];

    /**
     * @var array 允许的协议类型（白名单） 为空则不进行过滤
     */
    protected $allowedProtocols = [];

    /** Supported transports indexed by protocol and TLS mode. */
    protected const TRANSPORTS = [];

    protected const HYSTERIA2_OBFUSCATION = [];

    /**
     * 构造函数
     *
     * @param array $user 用户信息
     * @param array $servers 服务器信息
     * @param string|null $clientName 客户端名称
     * @param string|null $clientVersion 客户端版本
     * @param string|null $userAgent 原始 User-Agent
     */
    public function __construct($user, $servers, $clientName = null, $clientVersion = null, $userAgent = null)
    {
        $this->user = $user;
        $this->servers = $servers;
        $this->clientName = $clientName;
        $this->clientVersion = $clientVersion;
        $this->userAgent = $userAgent;
        $this->protocolRequirements = $this->normalizeProtocolRequirements($this->protocolRequirements);
        $this->servers = HookManager::filter('protocol.servers.filtered', $this->filterServersByVersion());
        $this->servers = collect($this->servers)->filter(static function ($server): bool {
            if (($server['type'] ?? null) !== 'hysteria'
                || (int) data_get($server, 'protocol_settings.version') !== 2
                || !data_get($server, 'protocol_settings.obfs.open')) {
                return true;
            }

            $password = data_get($server, 'protocol_settings.obfs.password');
            return is_string($password) && $password !== ''
                && in_array(data_get($server, 'protocol_settings.obfs.type'), static::HYSTERIA2_OBFUSCATION, true);
        })->values()->all();
    }

    /**
     * 获取协议标识
     *
     * @return array
     */
    public function getFlags(): array
    {
        return $this->flags;
    }

    /**
     * 处理请求
     *
     * @return mixed
     */
    abstract public function handle();

    /**
     * 根据客户端版本过滤不兼容的服务器
     *
     * @return array
     */
    protected function filterServersByVersion()
    {
        $this->filterByAllowedProtocols();
        $hasGlobalConfig = isset($this->protocolRequirements['*']);
        $hasClientConfig = isset($this->protocolRequirements[$this->clientName]);

        if (!$hasGlobalConfig && !$hasClientConfig) {
            return $this->servers;
        }

        return collect($this->servers)
            ->filter(fn($server) => $this->isCompatible($server))
            ->values()
            ->all();
    }

    /**
     * 检查服务器是否与当前客户端兼容
     *
     * @param array $server 服务器信息
     * @return bool
     */
    protected function isCompatible($server)
    {
        $serverType = $server['type'] ?? null;
        if (isset($this->protocolRequirements['*'][$serverType])) {
            $globalRequirements = $this->protocolRequirements['*'][$serverType];
            if (!$this->checkRequirements($globalRequirements, $server)) {
                return false;
            }
        }

        if (!isset($this->protocolRequirements[$this->clientName][$serverType])) {
            return true;
        }

        $requirements = $this->protocolRequirements[$this->clientName][$serverType];
        return $this->checkRequirements($requirements, $server);
    }

    /**
     * 检查版本要求
     *
     * @param array $requirements 要求配置
     * @param array $server 服务器信息
     * @return bool
     */
    private function checkRequirements(array $requirements, array $server): bool
    {
        if (!$this->meetsMinimumVersion($requirements['base_version'] ?? '0.0.0')) {
            return false;
        }

        $incompatible = $requirements['incompatible'] ?? false;
        if ($incompatible === true) {
            return false;
        }
        // Each incompatible field lists values that this renderer rejects.
        foreach ((array) $incompatible as $field => $values) {
            if (in_array(data_get($server, $field), (array) $values, true)) {
                return false;
            }
        }

        foreach ($requirements as $field => $rule) {
            if ($field === 'base_version' || $field === 'incompatible') {
                continue;
            }

            $values = $rule['whitelist'] ?? $rule;
            if (isset($rule['whitelist']) && array_is_list($values)) {
                $values = array_fill_keys($values, '0.0.0');
            }

            $actual = data_get($server, $field);
            if (is_bool($actual)) {
                $actual = (int) $actual;
            }
            if ((!is_string($actual) && !is_int($actual)) || !isset($values[$actual])) {
                if ($rule['strict'] ?? false) {
                    return false;
                }
                continue;
            }
            if (!$this->meetsMinimumVersion($values[$actual])) {
                return false;
            }
        }

        return true;
    }

    private function meetsMinimumVersion(string $minimum): bool
    {
        return $minimum === '0.0.0'
            || (is_string($this->clientVersion)
                && preg_match('/^[0-9]+(?:\.[0-9]+)*$/D', $this->clientVersion)
                && version_compare($this->clientVersion, $minimum, '>='));
    }

    protected static function supportsTransport(string $protocol, array $settings): bool
    {
        $tls = $settings['tls'] ?? ($protocol === 'trojan' ? 1 : 0);
        $network = $settings['network'] ?? 'tcp';
        if (!in_array($network, static::TRANSPORTS[$protocol][$tls] ?? [], true)) {
            return false;
        }

        $header = data_get($settings, 'network_settings.header.type') ?? 'none';
        return $network !== 'tcp'
            || in_array($header, ['none', 'tcp'], true)
            || ($header === 'http' && in_array('tcp-http', static::TRANSPORTS[$protocol][$tls], true));
    }

    /**
     * 检查当前客户端是否支持特定功能
     *
     * @param string $clientName 客户端名称
     * @param string $minVersion 最低版本要求
     * @param array $additionalConditions 额外条件检查
     * @return bool
     */
    protected function supportsFeature(string $clientName, string $minVersion, array $additionalConditions = []): bool
    {
        // 检查客户端名称
        if ($this->clientName !== $clientName) {
            return false;
        }

        // 检查版本号
        if (empty($this->clientVersion) || version_compare($this->clientVersion, $minVersion, '<')) {
            return false;
        }

        // 检查额外条件
        foreach ($additionalConditions as $condition) {
            if (!$condition) {
                return false;
            }
        }

        return true;
    }

    /**
     * 根据白名单过滤服务器
     *
     * @return void
     */
    protected function filterByAllowedProtocols(): void
    {
        if (!empty($this->allowedProtocols)) {
            $this->servers = collect($this->servers)
                ->filter(fn($server) => in_array($server['type'], $this->allowedProtocols))
                ->values()
                ->all();
        }
    }

    /**
     * 将平铺的协议需求转换为树形结构
     *
     * @param array $flat 平铺的协议需求
     * @return array 树形结构的协议需求
     */
    protected function normalizeProtocolRequirements(array $flat): array
    {
        $result = [];
        foreach ($flat as $key => $value) {
            if (!str_contains($key, '.')) {
                $result[$key] = $value;
                continue;
            }
            $segments = explode('.', $key, 3);
            if (count($segments) < 3) {
                $result[$segments[0]][$segments[1] ?? '*'][''] = $value;
                continue;
            }
            [$client, $type, $field] = $segments;
            $result[$client][$type][$field] = $value;
        }
        return $result;
    }
}