<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SB-RELAY-01: relay.config.v1 outbox row — one full config document per
 * (machine, version). The agent pulls the newest row and ACKs it; rollback
 * republishes an older payload under a fresh, higher version.
 */
class RelayConfigOutbox extends Model
{
    protected $table = 'suzu_relay_config_outbox';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACKED = 'acked';
    public const STATUS_SUPERSEDED = 'superseded';
}
