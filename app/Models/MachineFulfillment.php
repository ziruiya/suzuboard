<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Business plans batch 3: one fulfilment record per dedicated-VPS
 * (「独享整机」) order. Region is snapshotted at checkout and immutable;
 * the SLA clock runs from paid_at with a 3-hour delivery deadline.
 * There is deliberately NO refund state in this machine (D08).
 */
class MachineFulfillment extends Model
{
    protected $table = 'suzu_machine_fulfillments';
    protected $guarded = ['id'];

    protected $casts = [
        'sla_overdue' => 'boolean',
        'termination_review' => 'boolean',
        'paid_at' => 'datetime',
        'delivery_deadline_at' => 'datetime',
        'overdue_notice_at' => 'datetime',
        'last_progress_notice_at' => 'datetime',
        'user_choice_at' => 'datetime',
        'extended_deadline_at' => 'datetime',
        'resource_expires_at' => 'datetime',
        'lease_alert_at' => 'datetime',
        'provisioned_at' => 'datetime',
        'verified_at' => 'datetime',
        'delivered_at' => 'datetime',
        'grace_until' => 'datetime',
        'reclaimed_at' => 'datetime',
        'reclaim_checklist' => 'array',
    ];

    public const STATUS_WAITING_PAYMENT = 'WAITING_PAYMENT';
    public const STATUS_PAID_PENDING_PURCHASE = 'PAID_PENDING_PURCHASE';
    public const STATUS_PURCHASING = 'PURCHASING';
    public const STATUS_PURCHASE_BLOCKED = 'PURCHASE_BLOCKED';
    public const STATUS_WAITING_IP = 'WAITING_IP';
    public const STATUS_WAITING_INSTALL = 'WAITING_INSTALL';
    public const STATUS_INSTALLING = 'INSTALLING';
    public const STATUS_VERIFYING = 'VERIFYING';
    public const STATUS_VERIFY_FAILED = 'VERIFY_FAILED';
    public const STATUS_DELIVERED = 'DELIVERED';
    public const STATUS_SUSPENDED = 'SUSPENDED';
    public const STATUS_GRACE_HELD = 'GRACE_HELD';
    public const STATUS_RECLAIM_PENDING = 'RECLAIM_PENDING';
    public const STATUS_RECLAIMED = 'RECLAIMED';
    public const STATUS_CANCELLED = 'CANCELLED';

    /** States in which the delivery SLA clock is running. */
    public const IN_FLIGHT_STATUSES = [
        self::STATUS_PAID_PENDING_PURCHASE,
        self::STATUS_PURCHASING,
        self::STATUS_PURCHASE_BLOCKED,
        self::STATUS_WAITING_IP,
        self::STATUS_WAITING_INSTALL,
        self::STATUS_INSTALLING,
        self::STATUS_VERIFYING,
        self::STATUS_VERIFY_FAILED,
    ];

    /** States that count as "the customer holds this tier" (renewal target). */
    public const ACTIVE_STATUSES = [
        self::STATUS_PAID_PENDING_PURCHASE,
        self::STATUS_PURCHASING,
        self::STATUS_PURCHASE_BLOCKED,
        self::STATUS_WAITING_IP,
        self::STATUS_WAITING_INSTALL,
        self::STATUS_INSTALLING,
        self::STATUS_VERIFYING,
        self::STATUS_VERIFY_FAILED,
        self::STATUS_DELIVERED,
        self::STATUS_SUSPENDED,
        self::STATUS_GRACE_HELD,
        self::STATUS_RECLAIM_PENDING,
    ];

    /**
     * SB-ER-005: states a new purchase may actually renew/restore.
     * RECLAIM_PENDING is "held" for display purposes, but a repurchase
     * must NOT be folded into it: the reclaim is already dismantling
     * the old machine, and assignWithinOpen() has no transition out of
     * RECLAIM_PENDING — the paid order completed while nothing was
     * restored and no new fulfilment was created. A repurchase during
     * reclaim therefore starts a fresh fulfilment instead.
     */
    public const RENEWAL_STATUSES = [
        self::STATUS_PAID_PENDING_PURCHASE,
        self::STATUS_PURCHASING,
        self::STATUS_PURCHASE_BLOCKED,
        self::STATUS_WAITING_IP,
        self::STATUS_WAITING_INSTALL,
        self::STATUS_INSTALLING,
        self::STATUS_VERIFYING,
        self::STATUS_VERIFY_FAILED,
        self::STATUS_DELIVERED,
        self::STATUS_SUSPENDED,
        self::STATUS_GRACE_HELD,
    ];

    public const CHOICE_WAIT_ORIGINAL = 'WAIT_ORIGINAL';
    public const CHOICE_SWITCH_REGION = 'SWITCH_REGION';

    /** Reclaim checklist steps, in order (D07 reclaim flow). */
    public const RECLAIM_STEPS = [
        'entitlement_stopped',
        'retention_elapsed',
        'token_and_grants_revoked',
        'vps_confirmed_stopped',
        'reclaim_recorded',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(ServerMachine::class, 'machine_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ServerGroup::class, 'group_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MachineFulfillmentLog::class, 'fulfillment_id');
    }

    /** @return array<int, int> the three dedicated node ids (existing only) */
    public function nodeIds(): array
    {
        return array_values(array_filter([
            $this->reality_server_id ? (int) $this->reality_server_id : null,
            $this->ss1_server_id ? (int) $this->ss1_server_id : null,
            $this->sw1_server_id ? (int) $this->sw1_server_id : null,
        ]));
    }

    public function isInFlight(): bool
    {
        return in_array($this->status, self::IN_FLIGHT_STATUSES, true);
    }
}
