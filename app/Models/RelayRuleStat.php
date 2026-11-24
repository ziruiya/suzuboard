<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SB-RELAY-01: per-rule traffic aggregate. record_type ('d'|'m') is part of
 * the unique key (AUD-036); record_at is the UTC day/month boundary as a
 * unix timestamp. Relay traffic is panel-side accounting only — it never
 * flows into v2_user.u/d.
 */
class RelayRuleStat extends Model
{
    protected $table = 'suzu_relay_rule_stats';

    protected $guarded = ['id'];
}
