<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SB-RELAY-01 (GPT round 2, B03): a durable record of one landing
 * machine public_address change and its fleet-wide follow-through.
 *
 * `followers` maps each follower relay machine id to its outcome:
 * {status: published|failed|confirmed, version, error, attempts,
 * updated_at}. The record is resumable: the reconcile loop (or an
 * admin via the API) re-publishes failed followers and re-checks ACK
 * convergence until every follower is `confirmed` (its applied version
 * covers the version this change published for it), at which point the
 * change is `complete`. This replaces round 1's audit-log-only
 * accounting, which left a failed follower with nothing to retry from.
 */
class RelayAddressChange extends Model
{
    protected $table = 'suzu_relay_address_changes';

    protected $guarded = ['id'];

    protected $casts = [
        'followers' => 'array',
        'precheck' => 'array',
        'completed_at' => 'datetime',
    ];

    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_AWAITING_ACK = 'awaiting_ack';
    public const STATUS_COMPLETE = 'complete';

    public function machine(): BelongsTo
    {
        return $this->belongsTo(ServerMachine::class, 'machine_id');
    }

    public function isOpen(): bool
    {
        return $this->status !== self::STATUS_COMPLETE;
    }
}
