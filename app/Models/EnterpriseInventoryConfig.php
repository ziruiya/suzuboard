<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseInventoryConfig extends Model
{
    /** D03: every SKU keeps this many READY kits in stock. */
    public const DEFAULT_TARGET_LEVEL = 3;

    protected $table = 'suzu_enterprise_inventory_config';
    protected $guarded = ['id'];
    protected $casts = [
        'purpose_id' => 'integer',
        'target_level' => 'integer',
        'current_level' => 'integer',
        'lead_days' => 'integer',
    ];

    public function purpose(): BelongsTo
    {
        return $this->belongsTo(EnterprisePurpose::class, 'purpose_id');
    }
}
