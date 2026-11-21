<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit record for everything that happens to an endpoint
 * contract: snapshots, supersessions, preflights, DNS cutovers, rollbacks.
 */
class SuzuEndpointChange extends Model
{
    protected $table = 'suzu_endpoint_changes';

    protected $guarded = ['id'];

    protected $casts = [
        'contract_id' => 'integer',
        'actor_id' => 'integer',
        'from_revision' => 'integer',
        'to_revision' => 'integer',
        'related_change_id' => 'integer',
        'old_binding' => 'array',
        'new_binding' => 'array',
        'precheck_result' => 'array',
        'postcheck_result' => 'array',
    ];

    public const ACTION_SNAPSHOT = 'snapshot';
    public const ACTION_SUPERSEDE = 'supersede';
    public const ACTION_PREFLIGHT = 'preflight';
    public const ACTION_DNS_CUTOVER = 'dns_cutover';
    public const ACTION_DNS_ROLLBACK = 'dns_rollback';

    public const ROLLBACK_NONE = 'none';
    public const ROLLBACK_DONE = 'rolled_back';
    public const ROLLBACK_FAILED = 'failed';

    public function contract(): BelongsTo
    {
        return $this->belongsTo(SuzuEndpointContract::class, 'contract_id');
    }
}
