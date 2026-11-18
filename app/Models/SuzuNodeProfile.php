<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SuzuNodeProfile extends Model
{
    protected $table = 'suzu_node_profiles';
    protected $guarded = ['id'];
    protected $casts = [
        'server_id' => 'integer',
        'schema_version' => 'integer',
        'udp_enabled' => 'boolean',
    ];
}
