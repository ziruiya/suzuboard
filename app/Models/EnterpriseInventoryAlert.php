<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnterpriseInventoryAlert extends Model
{
    protected $table = 'suzu_enterprise_inventory_alerts';
    protected $guarded = ['id'];
    protected $casts = [
        'purpose_id' => 'integer',
        'kit_id' => 'integer',
        'resolved_at' => 'datetime',
    ];

    public const STATUS_OPEN = 'OPEN';
    public const STATUS_ACKED = 'ACKED';
    public const STATUS_RESOLVED = 'RESOLVED';

    public const TYPE_RULE_STOCK_EMPTY = 'rule_stock_empty';
    public const TYPE_RULE_STOCK_LOW = 'rule_stock_low';
    public const TYPE_BLUEPRINT_INCOMPLETE = 'blueprint_incomplete';
    public const TYPE_WAITING_TOO_LONG = 'waiting_too_long';
    public const TYPE_HEALTH_QUARANTINED = 'health_quarantined';
    public const TYPE_DNS_ALIAS_PENDING = 'dns_alias_pending';
    public const TYPE_DELIVERY_VERIFY_FAILED = 'delivery_verify_failed';
    public const TYPE_STOCK_LOW = 'stock_low';

    public static function raise(?int $purposeId, ?int $kitId, string $type, string $message): self
    {
        // One open alert per (kit, type) / (purpose, type) at a time.
        $existing = self::where('alert_type', $type)
            ->where('status', self::STATUS_OPEN)
            ->when($kitId, fn($q) => $q->where('kit_id', $kitId), fn($q) => $q->whereNull('kit_id'))
            ->when($purposeId, fn($q) => $q->where('purpose_id', $purposeId), fn($q) => $q->whereNull('purpose_id'))
            ->first();
        if ($existing) {
            return $existing;
        }
        return self::create([
            'purpose_id' => $purposeId,
            'kit_id' => $kitId,
            'alert_type' => $type,
            'message' => $message,
            'status' => self::STATUS_OPEN,
        ]);
    }
}
