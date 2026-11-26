<?php

namespace App\Services;

use App\Models\Server;
use App\Models\SuzuEndpointBinding;
use App\Models\SuzuEndpointChange;
use App\Models\SuzuEndpointContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Builds and guards endpoint contracts.
 *
 * A contract is an immutable snapshot of the client-visible parameters of
 * one v2_server node. If the live node later drifts from the snapshot, the
 * service refuses to overwrite history: it supersedes the old contract,
 * issues a new version and raises an alarm (log + audit row + return flag),
 * because drift means subscriptions in the wild no longer match what the
 * panel operator believes is frozen.
 */
class SuzuEndpointContractService
{
    /** Auth namespaces by node type: how client credentials are derived. */
    private const AUTH_NAMESPACES = [
        Server::TYPE_VLESS => 'user-uuid',
        Server::TYPE_VMESS => 'user-uuid',
        Server::TYPE_TROJAN => 'user-uuid',
        Server::TYPE_TUIC => 'user-uuid',
        Server::TYPE_HYSTERIA => 'user-uuid',
        Server::TYPE_ANYTLS => 'user-uuid',
        Server::TYPE_SHADOWSOCKS => 'server-derived',
        Server::TYPE_HTTP => 'user-pass',
        Server::TYPE_SOCKS => 'user-pass',
        Server::TYPE_NAIVE => 'user-pass',
        Server::TYPE_MIERU => 'user-pass',
    ];

    /**
     * Extract the frozen snapshot fields from a live node.
     *
     * The Reality public key itself is never stored: only its SHA-256
     * fingerprint. No private key or credential material is read here.
     */
    public function extractFrozenFields(Server $server): array
    {
        $settings = $server->protocol_settings ?? [];
        $reality = $settings['reality_settings'] ?? null;
        $networkSettings = $settings['network_settings'] ?? [];

        $publicKey = is_array($reality) ? ($reality['public_key'] ?? null) : null;

        $sni = null;
        if (is_array($reality) && !empty($reality['server_name'])) {
            $sni = $reality['server_name'];
        } elseif (!empty($settings['tls_settings']['server_name'])) {
            $sni = $settings['tls_settings']['server_name'];
        } elseif (!empty($settings['server_name'])) {
            $sni = $settings['server_name'];
        }

        $range = null;
        if (preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*$/D', (string) $server->port, $matches)) {
            $range = (int) $matches[1] . '-' . (int) $matches[2];
        }

        return [
            'client_port_range' => $range,
            'logical_node_id' => (int) $server->id,
            'legacy_hostname' => (string) $server->host,
            'client_port' => (int) $server->port,
            'server_port' => $server->server_port !== null ? (int) $server->server_port : null,
            'protocol' => (string) $server->type,
            'transport' => $settings['network'] ?? null,
            'flow' => $settings['flow'] ?? null,
            'sni' => $sni,
            'reality_public_key_fingerprint' => $publicKey ? hash('sha256', (string) $publicKey) : null,
            'short_id' => is_array($reality) ? ($reality['short_id'] ?? null) : null,
            'grpc_service_name' => is_array($networkSettings) ? ($networkSettings['serviceName'] ?? null) : null,
            'ws_path' => is_array($networkSettings) ? ($networkSettings['path'] ?? null) : null,
            'auth_namespace' => self::AUTH_NAMESPACES[$server->type] ?? 'user-uuid',
        ];
    }

    /** Canonical hash over the frozen fields (stable key order, no locale). */
    public function contractHash(array $frozenFields): string
    {
        $canonical = [];
        foreach (SuzuEndpointContract::FROZEN_FIELDS as $field) {
            $canonical[$field] = $frozenFields[$field] ?? null;
        }
        // Scalar ports keep their v1 hashes. Range contracts use the v2 hash domain.
        $range = $canonical['client_port_range'];
        unset($canonical['client_port_range']);
        if ($range !== null) {
            $canonical['client_port_range'] = $range;
            $canonical['hash_version'] = 2;
        }
        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Snapshot a node into a contract.
     *
     * @return array{contract: SuzuEndpointContract, alarm: bool, drift: array, created: bool}
     */
    public function snapshotFromServer(Server $server, ?int $actorId = null): array
    {
        $fields = $this->extractFrozenFields($server);
        $hash = $this->contractHash($fields);

        $latest = SuzuEndpointContract::where('logical_node_id', $server->id)
            ->orderByDesc('version')
            ->first();

        if ($latest && $latest->contract_hash === $hash) {
            return ['contract' => $latest, 'alarm' => false, 'drift' => [], 'created' => false];
        }

        $drift = $latest ? $this->diffFields($latest->frozenFields(), $fields) : [];

        return DB::transaction(function () use ($server, $fields, $hash, $latest, $drift, $actorId) {
            if ($latest && $latest->status === SuzuEndpointContract::STATUS_ACTIVE) {
                $latest->status = SuzuEndpointContract::STATUS_SUPERSEDED;
                $latest->save();
            }

            $contract = SuzuEndpointContract::create(array_merge($fields, [
                'version' => $latest ? $latest->version + 1 : 1,
                'contract_hash' => $hash,
                'status' => SuzuEndpointContract::STATUS_ACTIVE,
            ]));

            $binding = SuzuEndpointBinding::firstOrCreate(
                ['contract_id' => $contract->id],
                [
                    'entry_mode' => $this->inferEntryMode($fields),
                    'origin_id' => $server->machine_id !== null ? (int) $server->machine_id : null,
                    'health_status' => SuzuEndpointBinding::HEALTH_UNKNOWN,
                    'revision' => 1,
                ]
            );

            SuzuEndpointChange::create([
                'contract_id' => $contract->id,
                'action' => $latest ? SuzuEndpointChange::ACTION_SUPERSEDE : SuzuEndpointChange::ACTION_SNAPSHOT,
                'actor_id' => $actorId,
                'from_revision' => $latest ? $binding->revision : null,
                'to_revision' => $binding->revision,
                'new_binding' => $binding->auditSnapshot(),
                'precheck_result' => $latest ? ['drift_fields' => $drift] : null,
                'rollback_status' => SuzuEndpointChange::ROLLBACK_NONE,
            ]);

            if ($latest) {
                Log::warning('SuzuEndpoint: node parameters drifted from frozen contract; new contract version issued.', [
                    'logical_node_id' => $server->id,
                    'old_contract_id' => $latest->id,
                    'new_contract_id' => $contract->id,
                    'drift_fields' => $drift,
                ]);
            }

            return ['contract' => $contract, 'alarm' => $latest !== null, 'drift' => $drift, 'created' => true];
        });
    }

    /**
     * Recompute the live node's hash and compare it with the contract.
     *
     * @return array{ok: bool, drift: array, live_hash: ?string}
     */
    public function verifyIntegrity(SuzuEndpointContract $contract): array
    {
        $server = Server::find($contract->logical_node_id);
        if (!$server) {
            return ['ok' => false, 'drift' => ['logical_node_id' => ['contract' => $contract->logical_node_id, 'live' => null]], 'live_hash' => null];
        }
        $live = $this->extractFrozenFields($server);
        $liveHash = $this->contractHash($live);
        if ($liveHash === $contract->contract_hash) {
            return ['ok' => true, 'drift' => [], 'live_hash' => $liveHash];
        }
        return ['ok' => false, 'drift' => $this->diffFields($contract->frozenFields(), $live), 'live_hash' => $liveHash];
    }

    /** @return array<string, array{contract: mixed, live: mixed}> */
    public function diffFields(array $contractFields, array $liveFields): array
    {
        $drift = [];
        foreach (SuzuEndpointContract::FROZEN_FIELDS as $field) {
            $a = $contractFields[$field] ?? null;
            $b = $liveFields[$field] ?? null;
            // Normalise numeric strings so "8443" vs 8443 is not drift.
            if (is_numeric($a) && is_numeric($b)) {
                $a += 0;
                $b += 0;
            }
            if ($a !== $b) {
                $drift[$field] = ['contract' => $a, 'live' => $b];
            }
        }
        return $drift;
    }

    private function inferEntryMode(array $fields): string
    {
        // A client port different from the origin listen port means a relay
        // (VX) fronts the node; equal ports mean clients resolve the name
        // straight to an origin via DNS.
        if ($fields['server_port'] !== null && ($fields['client_port_range'] !== null || $fields['client_port'] !== $fields['server_port'])) {
            return SuzuEndpointBinding::ENTRY_VX;
        }
        return SuzuEndpointBinding::ENTRY_DNS;
    }
}
