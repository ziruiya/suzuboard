<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * App\Models\EnterprisePurpose — one enterprise SKU (e.g. JP_TIKTOK).
 *
 * The row doubles as the build blueprint for kits of this SKU: machine,
 * wire protocol, entry provider and port bases are filled in by ops before
 * the kit builder can produce stock.
 */
class EnterprisePurpose extends Model
{
    protected $table = 'suzu_enterprise_purpose';
    protected $guarded = ['id'];
    protected $casts = [
        'is_active' => 'boolean',
        'machine_id' => 'integer',
        'landing_port_base' => 'integer',
        'entry_port_base' => 'integer',
    ];

    public const PROTOCOL_REALITY = 'vless_reality';
    public const PROTOCOL_SW1 = 'vless_tls_ws';

    public const PROVIDER_CSMZZMB = 'csmzzmb';
    public const PROVIDER_GOST = 'gost';
    public const PROVIDER_VX = 'vx';

    public function kits(): HasMany
    {
        return $this->hasMany(EnterpriseInventoryKit::class, 'purpose_id');
    }

    public function config(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(EnterpriseInventoryConfig::class, 'purpose_id');
    }

    public function blueprintComplete(): bool
    {
        return $this->machine_id && $this->protocol && $this->entry_provider && $this->dns_alias_suffix;
    }
}
