<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogEntryEvent extends Model
{
    protected $table = 'log_entry_events';
    protected $primaryKey = 'incident_id';
    protected $keyType = 'string';
    public $incrementing = false;
    public $timestamps = false;

    protected $casts = ['historical_backfill' => 'boolean'];

    public const ATTRIBUTION = [
        'UNKNOWN', 'PROVIDER_ORIGIN', 'DNS_CHANGED', 'UPSTREAM_NETWORK_ANOMALY',
        'BACKEND_FAILURE', 'INSUFFICIENT_EVIDENCE',
    ];
}
