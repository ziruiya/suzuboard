<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SB-RELAY-01 (GPT round 2, B03): a durable record of one landing
 * machine public_address change and its fleet-wide follow-through.
 *
 * `followers` maps each follower relay machine id to its outcome:
 * {status: pending|published|failed|confirmed|superseded, version,
 * error, attempts, updated_at}. The record is resumable: the reconcile
 * loop (or an admin via the API) re-publishes unpublished followers
 * (pending included — GPT round 3, N02) and re-checks ACK convergence
 * until every follower is `confirmed`, at which point the change is
 * `complete`. Confirmation is content-based (N02): the applied
 * document must embody this change's retarget, not merely carry a
 * large enough version number, and both documents must exist as
 * evidence — a missing document blocks confirmation, fail-closed
 * (N02-2, GPT round 4). An unpublished follower whose change was
 * overtaken by a newer change for the same machine closes as
 * `superseded` without adopting or publishing the newer change's
 * configuration (N02-1, GPT round 4); a follower overtaken after
 * publishing closes as `superseded` too, and so does the change
 * when no follower is left converging. This replaces round 1's
 * audit-log-only accounting, which left a failed follower with
 * nothing to retry from.
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
    public const STATUS_SUPERSEDED = 'superseded';

    public function machine(): BelongsTo
    {
        return $this->belongsTo(ServerMachine::class, 'machine_id');
    }

    public function isOpen(): bool
    {
        return $this->status !== self::STATUS_COMPLETE
            && $this->status !== self::STATUS_SUPERSEDED;
    }
}
