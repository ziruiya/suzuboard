<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnterpriseInventoryLog extends Model
{
    protected $table = 'suzu_enterprise_inventory_logs';
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = [
        'kit_id' => 'integer',
        'actor_id' => 'integer',
        'created_at' => 'datetime',
    ];

    public const ACTOR_SYSTEM = 'system';
    public const ACTOR_ADMIN = 'admin';
    public const ACTOR_USER = 'user';

    public static function record(?int $kitId, string $event, ?string $detail = null, string $actorType = self::ACTOR_SYSTEM, ?int $actorId = null): self
    {
        return self::create([
            'kit_id' => $kitId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'event' => $event,
            'detail' => $detail,
            'created_at' => now(),
        ]);
    }
}
