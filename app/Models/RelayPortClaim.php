<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SB-RELAY-01: port occupancy ledger. One row per (machine, transport,
 * bind family, bind ip, port); a 'both' rule owns two rows. The UNIQUE key
 * is the hard guard against double-binding a listen port on one machine.
 */
class RelayPortClaim extends Model
{
    protected $table = 'suzu_relay_port_claims';

    protected $guarded = ['id'];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(RelayRule::class, 'rule_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(ServerMachine::class, 'machine_id');
    }
}
