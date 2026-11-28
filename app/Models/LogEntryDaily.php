<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogEntryDaily extends Model
{
    protected $table = 'log_entry_daily';
    protected $primaryKey = null;
    public $incrementing = false;
    public $timestamps = false;
}
