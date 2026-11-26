<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Immutable endpoint contract snapshot for one logical node (v2_server row).
 *
 * A contract freezes every client-visible parameter of a subscription line:
 * hostname, client port, protocol, transport, SNI, Reality public-key
 * fingerprint, short id, gRPC service name, WS path and the auth namespace.
 * Once written it is read-only: the only permitted mutation is the status
 * transition active -> superseded, performed by
 * SuzuEndpointContractService when the live node drifts and a new contract
 * version is issued. Any other write throws.
 */
class SuzuEndpointContract extends Model
{
    protected $table = 'suzu_endpoint_contracts';

    protected $guarded = ['id'];

    protected $casts = [
        'logical_node_id' => 'integer',
        'version' => 'integer',
        'client_port' => 'integer',
        'server_port' => 'integer',
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUPERSEDED = 'superseded';

    /** Columns that make up the frozen snapshot (and the contract hash). */
    public const FROZEN_FIELDS = [
        'logical_node_id',
        'legacy_hostname',
        'client_port',
        'client_port_range',
        'server_port',
        'protocol',
        'transport',
        'flow',
        'sni',
        'reality_public_key_fingerprint',
        'short_id',
        'grpc_service_name',
        'ws_path',
        'auth_namespace',
    ];

    protected static function booted(): void
    {
        static::updating(function (SuzuEndpointContract $contract) {
            $dirty = array_keys($contract->getDirty());
            $allowed = ['status', 'updated_at'];
            $illegal = array_diff($dirty, $allowed);
            if (!empty($illegal)) {
                throw new LogicException(
                    'Endpoint contracts are immutable; cannot modify: ' . implode(', ', $illegal)
                    . '. Issue a new contract version instead.'
                );
            }
            if (in_array('status', $dirty, true)
                && !($contract->getOriginal('status') === self::STATUS_ACTIVE
                    && $contract->status === self::STATUS_SUPERSEDED)) {
                throw new LogicException('Only the active -> superseded status transition is permitted on a contract.');
            }
        });

        static::deleting(function () {
            throw new LogicException('Endpoint contracts are immutable and cannot be deleted.');
        });
    }

    public function binding(): HasOne
    {
        return $this->hasOne(SuzuEndpointBinding::class, 'contract_id');
    }

    public function changes(): HasMany
    {
        return $this->hasMany(SuzuEndpointChange::class, 'contract_id');
    }

    public function server(): ?Server
    {
        return Server::find($this->logical_node_id);
    }

    /** The frozen snapshot as a plain array, in canonical field order. */
    public function frozenFields(): array
    {
        $fields = [];
        foreach (self::FROZEN_FIELDS as $field) {
            $fields[$field] = $this->getAttribute($field);
        }
        return $fields;
    }
}
