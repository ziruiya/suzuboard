<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnterpriseInventoryNode extends Model
{
    protected $table = 'suzu_enterprise_inventory_nodes';
    protected $guarded = ['id'];
    protected $casts = [
        'kit_id' => 'integer',
        'server_id' => 'integer',
        'port' => 'integer',
        'landing_port' => 'integer',
    ];

    public const ROLE_PRIMARY = 'primary';
    public const ROLE_BACKUP = 'backup';

    public function kit(): BelongsTo
    {
        return $this->belongsTo(EnterpriseInventoryKit::class, 'kit_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }
}
