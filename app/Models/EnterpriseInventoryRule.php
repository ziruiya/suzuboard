<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry-forwarding rule. For providers without an API (csmzzmb) ops
 * pre-creates rules in bulk and registers them here as READY stock; the kit
 * builder consumes a primary/backup pair when it builds a kit.
 */
class EnterpriseInventoryRule extends Model
{
    protected $table = 'suzu_enterprise_inventory_rules';
    protected $guarded = ['id'];
    protected $casts = [
        'purpose_id' => 'integer',
        'kit_id' => 'integer',
        'rule_slot' => 'integer',
        'public_entry_port' => 'integer',
        'target_port' => 'integer',
    ];

    public const STATUS_READY = 'READY';
    public const STATUS_IN_USE = 'IN_USE';
    public const STATUS_RESERVED = 'RESERVED';
    public const STATUS_DISABLED = 'DISABLED';

    public function purpose(): BelongsTo
    {
        return $this->belongsTo(EnterprisePurpose::class, 'purpose_id');
    }

    public function kit(): BelongsTo
    {
        return $this->belongsTo(EnterpriseInventoryKit::class, 'kit_id');
    }
}
