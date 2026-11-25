<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SB-RELAY-01 (GPT round 2, B01): one outstanding revocation obligation.
 *
 * An obligation is born when the panel stops publishing a rule the agent
 * had confirmed (ACK applied) it was serving — by disable, delete,
 * BROKEN exclusion, or any other publish-time drop — and dies only when
 * the agent ACKs an applied document that does not contain the rule
 * (per-rule confirmation), or when the rule is published again (the
 * agent serving it is then exactly what the panel wants). It lives in
 * its own table precisely so that deleting the rule row cannot delete
 * the obligation with it.
 */
class RelayRevocation extends Model
{
    protected $table = 'suzu_relay_revocations';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public const REASON_DISABLED = 'disabled';
    public const REASON_DELETED = 'deleted';
    public const REASON_BROKEN = 'broken';
    public const REASON_EXCLUDED = 'excluded';

    public function machine(): BelongsTo
    {
        return $this->belongsTo(ServerMachine::class, 'machine_id');
    }

    /** The still-unconfirmed obligation for a rule, if one exists. */
    public static function openFor(int $machineId, string $ruleCode): ?self
    {
        return self::where('machine_id', $machineId)
            ->where('rule_code', $ruleCode)
            ->whereNull('confirmed_at')
            ->first();
    }

    /**
     * Record (or refresh) the obligation for a rule the panel is about
     * to stop publishing. Idempotent: an already-open obligation keeps
     * its original reason and creation time.
     */
    public static function record(int $machineId, string $ruleCode, ?int $ruleId, string $reason, int $sinceVersion = 0): self
    {
        $open = self::openFor($machineId, $ruleCode);
        if ($open) {
            if ($sinceVersion > 0 && (int) $open->since_version === 0) {
                $open->forceFill(['since_version' => $sinceVersion])->save();
            }
            return $open;
        }
        $row = new self([
            'machine_id' => $machineId,
            'rule_id' => $ruleId,
            'rule_code' => $ruleCode,
            'reason' => $reason,
            'since_version' => $sinceVersion,
            'created_at' => now(),
        ]);
        $row->save();
        return $row;
    }
}
