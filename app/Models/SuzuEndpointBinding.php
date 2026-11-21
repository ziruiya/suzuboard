<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mutable routing state for one endpoint contract: which DNS record fronts
 * it, which origin machine currently serves it, and the optimistic-lock
 * revision every write must present.
 */
class SuzuEndpointBinding extends Model
{
    protected $table = 'suzu_endpoint_bindings';

    protected $guarded = ['id'];

    protected $casts = [
        'contract_id' => 'integer',
        'origin_id' => 'integer',
        'standby_origin_id' => 'integer',
        'revision' => 'integer',
        'last_preflight' => 'array',
    ];

    public const ENTRY_DNS = 'dns';
    public const ENTRY_VX = 'vx';
    public const ENTRY_DIRECT = 'direct';

    public const HEALTH_UNKNOWN = 'unknown';
    public const HEALTH_PASSED = 'passed';
    public const HEALTH_DEGRADED = 'degraded';
    public const HEALTH_FAILED = 'failed';
    public const HEALTH_ERROR = 'error';

    public function contract(): BelongsTo
    {
        return $this->belongsTo(SuzuEndpointContract::class, 'contract_id');
    }

    /** Snapshot of the routing-relevant columns, for audit JSON columns. */
    public function auditSnapshot(): array
    {
        return [
            'entry_mode' => $this->entry_mode,
            'dns_zone_id' => $this->dns_zone_id,
            'dns_record_id' => $this->dns_record_id,
            'current_entry_ip' => $this->current_entry_ip,
            'origin_id' => $this->origin_id,
            'standby_origin_id' => $this->standby_origin_id,
            'health_status' => $this->health_status,
            'revision' => $this->revision,
        ];
    }
}
