<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LogEntryMapping extends Model
{
    protected $table = 'log_entry_mappings';
    protected $primaryKey = 'mapping_id';
    public $timestamps = false;

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('provenance', '!=', 'patrol-detection');
    }
}
