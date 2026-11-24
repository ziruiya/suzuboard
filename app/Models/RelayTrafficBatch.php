<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SB-RELAY-01: immutable relay traffic ledger row. A batch is booked only
 * when its insert succeeds against the UNIQUE keys (batch_id, and
 * machine+rule+boot+seq); replays hit the unique key and are dropped, so a
 * batch can never be counted twice. Rows are never updated or deleted.
 */
class RelayTrafficBatch extends Model
{
    protected $table = 'suzu_relay_traffic_batches';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
