<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * App\Models\ServerMachine
 *
 * @property int $id
 * @property string $name 机器名称
 * @property string $token 认证 Token
 * @property string|null $notes 备注
 * @property int $auth_epoch WS authorization generation
 * @property bool $is_active 是否启用
 * @property string $role 机器角色 landing|relay（二选一，权威字段）
 * @property string|null $public_address 机器公网落地地址（IP 或主机名）
 * @property int|null $last_seen_at 最后心跳时间
 * @property array|null $load_status 负载状态
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Server> $servers 关联的节点
 */
class ServerMachine extends Model
{
    protected $table = 'v2_server_machine';

    protected $guarded = ['id', 'auth_epoch'];

    protected $casts = [
        'is_active' => 'boolean',
        'auth_epoch' => 'integer',
        'last_seen_at' => 'integer',
        'load_status' => 'array',
        'relay_enabled' => 'boolean',
        'relay_config_version' => 'integer',
        'relay_applied_version' => 'integer',
        'relay_applied_at' => 'timestamp',
        'relay_status_changed_at' => 'timestamp',
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
    ];

    protected $hidden = ['token', 'auth_epoch'];

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class, 'machine_id');
    }

    public function loadHistory(): HasMany
    {
        return $this->hasMany(ServerMachineLoadHistory::class, 'machine_id');
    }

    // ---- SB-RELAY-01 -------------------------------------------------

    /**
     * Machine role (owner ruling 2026-11-25): a machine is a landing
     * machine OR a relay machine, never both. `role` is the authoritative
     * field; relay_enabled/relay_status are only the operational switch
     * INSIDE the relay role and are inert on landing machines.
     */
    public const ROLE_LANDING = 'landing';
    public const ROLE_RELAY = 'relay';

    public function isRelayRole(): bool
    {
        return $this->role === self::ROLE_RELAY;
    }

    public function isLandingRole(): bool
    {
        return $this->role !== self::ROLE_RELAY;
    }

    public const RELAY_STATUS_DISABLED = 'DISABLED';
    public const RELAY_STATUS_ENABLED = 'ENABLED';
    public const RELAY_STATUS_DRAINING = 'DRAINING';

    /** Preflight resource classes: 2GB machines vs 1GB machines. */
    public const RELAY_TIERS = [
        'large' => ['rules' => 60, 'tcp' => 1000, 'udp' => 1024],
        'small' => ['rules' => 30, 'tcp' => 500, 'udp' => 512],
    ];

    public function relayRules(): HasMany
    {
        return $this->hasMany(RelayRule::class, 'machine_id');
    }

    public function relayRulesLimit(): int
    {
        return $this->relay_rules_limit
            ?? self::RELAY_TIERS[$this->relay_tier]['rules']
            ?? self::RELAY_TIERS['small']['rules'];
    }

    public function relayTcpLimit(): int
    {
        return $this->relay_tcp_limit
            ?? self::RELAY_TIERS[$this->relay_tier]['tcp']
            ?? self::RELAY_TIERS['small']['tcp'];
    }

    public function relayUdpLimit(): int
    {
        return $this->relay_udp_limit
            ?? self::RELAY_TIERS[$this->relay_tier]['udp']
            ?? self::RELAY_TIERS['small']['udp'];
    }

    /**
     * 生成新的随机 Token
     */
    public static function generateToken(): string
    {
        return Str::random(32);
    }

    /**
     * 更新最后心跳时间
     */
    public function updateHeartbeat(): bool
    {
        return $this->forceFill(['last_seen_at' => now()->timestamp])->save();
    }
}
