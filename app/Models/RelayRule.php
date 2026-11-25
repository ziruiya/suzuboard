<?php

namespace App\Models;

use App\Services\Relay\RelayPorts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SB-RELAY-01: one relay (L4 forward) rule on a relay machine.
 *
 * `enabled` is the admin intent; `status` is the runtime truth reported by
 * the panel/agent pair (NOT_APPLIED … BROKEN). The two never collapse into
 * one field (design v0.2).
 */
class RelayRule extends Model
{
    protected $table = 'suzu_relay_rules';

    protected $guarded = ['id'];

    protected $casts = [
        'enabled' => 'boolean',
        'allowlist_approved' => 'boolean',
        'allowlist_cidrs' => 'array',
        // R03: set while a previously-APPLIED rule's removal from the
        // published document has not yet been confirmed by an agent ACK.
        'revocation_pending_at' => 'datetime',
    ];

    public const STATUS_NOT_APPLIED = 'NOT_APPLIED';
    public const STATUS_APPLYING = 'APPLYING';
    public const STATUS_APPLIED = 'APPLIED';
    public const STATUS_DRAINING = 'DRAINING';
    public const STATUS_DISABLED = 'DISABLED';
    public const STATUS_CONFLICT = 'CONFLICT';
    public const STATUS_SUSPECT = 'SUSPECT';
    public const STATUS_DOWN = 'DOWN';
    public const STATUS_BROKEN = 'BROKEN';
    public const STATUS_UNKNOWN = 'UNKNOWN';

    public const TARGET_NODE = 'NODE';
    public const TARGET_MANUAL = 'MANUAL';

    /**
     * DB-layer port-pool guard (second layer under the service validation):
     * a relay rule can never persist a listen port outside the pool, no
     * matter which code path writes it.
     */
    protected static function booted(): void
    {
        static::saving(function (RelayRule $rule) {
            if ($rule->listen_port !== null && !RelayPorts::inPool((int) $rule->listen_port)) {
                throw new \InvalidArgumentException(
                    'Relay listen port ' . $rule->listen_port . ' is outside the pool '
                    . RelayPorts::POOL_MIN . '-' . RelayPorts::POOL_MAX
                );
            }
        });
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(ServerMachine::class, 'machine_id');
    }

    public function targetNode(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'target_node_id');
    }

    public function claims(): HasMany
    {
        return $this->hasMany(RelayPortClaim::class, 'rule_id');
    }

    public function stats(): HasMany
    {
        return $this->hasMany(RelayRuleStat::class, 'rule_id');
    }

    /** Transports this rule occupies: 'both' expands to tcp + udp. */
    public function transports(): array
    {
        return $this->transport === 'both' ? ['tcp', 'udp'] : [$this->transport];
    }

    public function bindFamily(): string
    {
        return str_contains((string) $this->listen_ip, ':') ? 'ipv6' : 'ipv4';
    }
}
