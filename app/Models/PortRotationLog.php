<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per customer-initiated port rotation (both business tiers).
 * Doubles as the 2-hour cooldown ledger (D08) and the audit trail:
 * credentials/SNI/subscription address never change in a rotation, only
 * the entry port and the landing port do.
 */
class PortRotationLog extends Model
{
    protected $table = 'suzu_port_rotation_logs';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = [
        'user_id' => 'integer',
        'assignment_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public const TYPE_KIT = 'kit';
    public const TYPE_MACHINE = 'machine';
}
