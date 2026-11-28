<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class LogEntry extends Model
{
    protected $table = 'log_entries';
    protected $primaryKey = 'entry_id';
    protected $guarded = ['entry_id', 'config_version', 'created_at', 'updated_at'];
    protected $attributes = ['shared_flag' => 'unknown', 'config_version' => 1];

    protected function asDateTime($value)
    {
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
            return Carbon::createFromFormat('Y-m-d H:i:s', $value, 'UTC');
        }

        return parent::asDateTime($value);
    }

    public function freshTimestamp()
    {
        return now('UTC');
    }
}
