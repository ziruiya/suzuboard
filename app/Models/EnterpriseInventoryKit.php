<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * App\Models\EnterpriseInventoryKit — one pre-built set of dedicated lines:
 * one stock server group + two nodes (primary/backup) on one landing machine.
 */
class EnterpriseInventoryKit extends Model
{
    protected $table = 'suzu_enterprise_inventory_kits';
    protected $guarded = ['id'];
    protected $casts = [
        'purpose_id' => 'integer',
        'stock_group_id' => 'integer',
        'sequence' => 'integer',
        'current_user_id' => 'integer',
        'current_order_id' => 'integer',
        'current_contract_version' => 'integer',
        'health_fail_count' => 'integer',
        'held_order_id' => 'integer',
        'last_probe_at' => 'datetime',
        'last_full_probe_at' => 'datetime',
        'verify_expires_at' => 'datetime',
        'hold_expires_at' => 'datetime',
        'assigned_at' => 'datetime',
        'grace_until' => 'datetime',
    ];

    public const STATUS_BUILDING = 'BUILDING';
    public const STATUS_VERIFYING = 'VERIFYING';
    public const STATUS_READY = 'READY';
    public const STATUS_HELD = 'HELD';
    public const STATUS_RESERVED = 'RESERVED';
    public const STATUS_ASSIGNED = 'ASSIGNED';
    public const STATUS_ASSIGNED_LEGACY = 'ASSIGNED_LEGACY';
    public const STATUS_GRACE_HELD = 'GRACE_HELD';
    public const STATUS_QUARANTINED = 'QUARANTINED';
    public const STATUS_RETIRED = 'RETIRED';

    /** Allowed forward transitions of the kit state machine. */
    public const TRANSITIONS = [
        self::STATUS_BUILDING => [self::STATUS_VERIFYING, self::STATUS_QUARANTINED, self::STATUS_RETIRED],
        self::STATUS_VERIFYING => [self::STATUS_READY, self::STATUS_BUILDING, self::STATUS_QUARANTINED],
        self::STATUS_READY => [self::STATUS_HELD, self::STATUS_QUARANTINED, self::STATUS_RETIRED],
        self::STATUS_HELD => [self::STATUS_RESERVED, self::STATUS_READY, self::STATUS_QUARANTINED],
        self::STATUS_RESERVED => [self::STATUS_ASSIGNED, self::STATUS_READY, self::STATUS_QUARANTINED],
        self::STATUS_ASSIGNED => [self::STATUS_GRACE_HELD, self::STATUS_QUARANTINED, self::STATUS_ASSIGNED_LEGACY],
        self::STATUS_ASSIGNED_LEGACY => [self::STATUS_ASSIGNED, self::STATUS_GRACE_HELD, self::STATUS_QUARANTINED],
        self::STATUS_GRACE_HELD => [self::STATUS_ASSIGNED, self::STATUS_QUARANTINED],
        self::STATUS_QUARANTINED => [self::STATUS_BUILDING, self::STATUS_RETIRED],
        self::STATUS_RETIRED => [],
    ];

    public function purpose(): BelongsTo
    {
        return $this->belongsTo(EnterprisePurpose::class, 'purpose_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(\App\Models\ServerGroup::class, 'stock_group_id');
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(EnterpriseInventoryNode::class, 'kit_id');
    }

    public function rules(): HasMany
    {
        return $this->hasMany(EnterpriseInventoryRule::class, 'kit_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(EnterpriseInventoryLog::class, 'kit_id');
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    /**
     * Assignment freshness gates from the design: a kit may only be handed
     * out when a full verification passed within the last 24h and a light
     * check passed within the last 6h.
     */
    public function assignmentFresh(): bool
    {
        if ($this->status !== self::STATUS_READY) {
            return false;
        }
        if (!$this->verify_expires_at || $this->verify_expires_at->isPast()) {
            return false;
        }
        if (!$this->last_probe_at || $this->last_probe_at->lt(now()->subHours(6))) {
            return false;
        }
        return true;
    }
}
