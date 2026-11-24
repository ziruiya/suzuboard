<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseOrderIntent extends Model
{
    protected $table = 'suzu_enterprise_order_intents';
    protected $guarded = ['id'];
    protected $casts = [
        'user_id' => 'integer',
        'order_id' => 'integer',
        'purpose_id' => 'integer',
        'kit_id' => 'integer',
        'hold_expires_at' => 'datetime',
        'waiting_since' => 'datetime',
        'last_alert_at' => 'datetime',
    ];

    public const STATUS_HELD = 'HELD';
    public const STATUS_RESERVED = 'RESERVED';
    public const STATUS_PAID_WAITING_INVENTORY = 'PAID_WAITING_INVENTORY';
    public const STATUS_DELIVERED = 'DELIVERED';
    public const STATUS_FAILED = 'FAILED';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function purpose(): BelongsTo
    {
        return $this->belongsTo(EnterprisePurpose::class, 'purpose_id');
    }

    public function kit(): BelongsTo
    {
        return $this->belongsTo(EnterpriseInventoryKit::class, 'kit_id');
    }
}
