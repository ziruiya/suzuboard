<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SB-RELAY-01: audit trail for every relay write (admin, agent, system).
 */
class RelayAuditLog extends Model
{
    protected $table = 'suzu_relay_audit_logs';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public static function record(?int $machineId, ?int $ruleId, string $actorType, ?int $actorId, string $action, ?string $detail = null): self
    {
        return self::create([
            'machine_id' => $machineId,
            'rule_id' => $ruleId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'detail' => $detail,
            'created_at' => now(),
        ]);
    }
}
