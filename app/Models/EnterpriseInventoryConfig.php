<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseInventoryConfig extends Model
{
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
