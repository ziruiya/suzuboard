<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable per-kit snapshot of the client-facing contract (entry address,
 * port, protocol parameters) captured when a kit is built. Complements —
 * does not replace — the SB-Endpoint-01 contract registry.
 */
class EnterpriseKitContractSnapshot extends Model
{
    protected $table = 'suzu_enterprise_kit_contract_snapshots';
    protected $guarded = ['id'];
    protected $casts = [
        'kit_id' => 'integer',
        'server_id' => 'integer',
        'contract_version' => 'integer',
        'entry_port' => 'integer',
        'target_port' => 'integer',
        'revision' => 'integer',
        'captured_at' => 'datetime',
    ];

    public function kit(): BelongsTo
    {
        return $this->belongsTo(EnterpriseInventoryKit::class, 'kit_id');
    }
}
