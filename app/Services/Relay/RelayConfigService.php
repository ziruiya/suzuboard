<?php

namespace App\Services\Relay;

use App\Models\RelayAuditLog;
use App\Models\RelayConfigOutbox;
use App\Models\RelayRule;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\ServerService;
use Illuminate\Support\Facades\DB;

/**
 * SB-RELAY-01 config channel relay.config.v1: Outbox + full pull +
 * persistent applied ACK. Every rule/machine change publishes a NEW full
 * config document under a higher version; rollback republishes an older
 * document's content under a fresh version (versions only ever move up).
 *
 * NODE-target rules follow their node: host/port are read live from the
 * node at publish time (SB-Endpoint philosophy — the binding follows the
 * node's current landing). A target node that was deleted or lost agent
 * eligibility turns the rule BROKEN: it is excluded from the document and
 * never silently rebound to anything else.
 */
class RelayConfigService
{
    public const PROTOCOL = 'relay.config.v1';

    /**
     * Build the effective config document for a machine (no persistence).
     * Side effect: rules whose NODE target lost eligibility are marked
     * BROKEN and excluded.
     */
    public function buildDocument(ServerMachine $machine): array
    {
        $rules = [];
        if ($machine->relay_enabled && $machine->relay_status !== ServerMachine::RELAY_STATUS_DRAINING) {
            RelayRule::where('machine_id', $machine->id)
                ->where('enabled', true)
                ->orderBy('id')
                ->get()
                ->each(function (RelayRule $rule) use (&$rules, $machine) {
                    $entry = $this->ruleEntry($rule);
                    if ($entry === null) {
                        return; // BROKEN, excluded
                    }
                    $rules[] = $entry;
                });
        }

        return [
            'protocol' => self::PROTOCOL,
            'machine_id' => (int) $machine->id,
            'relay_enabled' => (bool) $machine->relay_enabled
                && $machine->relay_status !== ServerMachine::RELAY_STATUS_DRAINING,
            'machine_status' => $machine->relay_status,
            'generated_at' => now()->toIso8601String(),
            'rules' => $rules,
        ];
    }

    private function ruleEntry(RelayRule $rule): ?array
    {
        $host = $rule->target_host;
        $port = (int) $rule->target_port;
        if ($rule->target_type === RelayRule::TARGET_NODE) {
            $node = Server::find($rule->target_node_id);
            if (!$node || !ServerService::isAgentDeliverable($node)) {
                if ($rule->status !== RelayRule::STATUS_BROKEN) {
                    $rule->forceFill([
                        'status' => RelayRule::STATUS_BROKEN,
                        'last_error' => $node ? 'target node lost agent eligibility' : 'target node deleted',
                    ])->save();
                    RelayAuditLog::record($rule->machine_id, $rule->id, 'system', null, 'rule.broken', $rule->rule_code);
                }
                return null;
            }
            // Follow the node's current landing; refresh the snapshot.
            $host = (string) $node->host;
            $port = (int) $node->server_port;
            $hash = RelayRuleService::nodeContractHash($node);
            if ($rule->target_snapshot_host !== $host || (int) $rule->target_snapshot_port !== $port
                || $rule->target_contract_hash !== $hash) {
                $rule->forceFill([
                    'target_snapshot_host' => $host,
                    'target_snapshot_port' => $port,
                    'target_contract_hash' => $hash,
                ])->save();
            }
        }

        return [
            'rule_code' => $rule->rule_code,
            'name' => $rule->name,
            'transport' => $rule->transport,
            'listen_ip' => $rule->listen_ip,
            'listen_port' => (int) $rule->listen_port,
            'target_type' => $rule->target_type,
            'target_node_id' => $rule->target_node_id ? (int) $rule->target_node_id : null,
            'target_host' => $host,
            'target_port' => $port,
            'target_resolve_mode' => $rule->target_resolve_mode,
            'allowlist_cidrs' => $rule->allowlist_cidrs ?: [],
            'max_connections' => (int) $rule->max_connections,
            'udp_max_sessions' => (int) $rule->udp_max_sessions,
            'udp_idle_timeout_sec' => (int) $rule->udp_idle_timeout_sec,
            'drain_timeout_sec' => (int) $rule->drain_timeout_sec,
            'buffer_bytes' => (int) $rule->buffer_bytes,
            'rate_in_bps' => $rule->rate_in_bps !== null ? (int) $rule->rate_in_bps : null,
            'rate_out_bps' => $rule->rate_out_bps !== null ? (int) $rule->rate_out_bps : null,
            'burst_bytes' => $rule->burst_bytes !== null ? (int) $rule->burst_bytes : null,
        ];
    }

    /** Publish the current effective document as the next version. */
    public function publish(ServerMachine $machine, string $actorType = 'system', ?int $actorId = null): RelayConfigOutbox
    {
        return DB::transaction(function () use ($machine, $actorType, $actorId) {
            $machine = ServerMachine::where('id', $machine->id)->lockForUpdate()->first() ?? $machine;
            $document = $this->buildDocument($machine);
            $version = (int) $machine->relay_config_version + 1;
            $document['version'] = $version;
            $payload = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $hash = hash('sha256', $payload);

            RelayConfigOutbox::where('machine_id', $machine->id)
                ->where('status', RelayConfigOutbox::STATUS_PENDING)
                ->update(['status' => RelayConfigOutbox::STATUS_SUPERSEDED]);

            $outbox = RelayConfigOutbox::create([
                'machine_id' => $machine->id,
                'version' => $version,
                'config_hash' => $hash,
                'payload' => $payload,
                'status' => RelayConfigOutbox::STATUS_PENDING,
                'created_by' => $actorId,
                'created_at' => now(),
            ]);

            $machine->forceFill([
                'relay_config_version' => $version,
                'relay_config_hash' => $hash,
            ])->save();

            $included = collect($document['rules'])->pluck('rule_code')->all();
            RelayRule::where('machine_id', $machine->id)->where('enabled', true)
                ->whereIn('rule_code', $included)
                ->where('status', '!=', RelayRule::STATUS_BROKEN)
                ->update([
                    'status' => RelayRule::STATUS_APPLYING,
                    'config_version' => $version,
                    'config_hash' => $hash,
                ]);
            RelayAuditLog::record($machine->id, null, $actorType, $actorId, 'config.publish', 'v' . $version . ' ' . $hash);
            return $outbox;
        });
    }

    /** Republish an older version's content under a new, higher version. */
    public function rollback(ServerMachine $machine, int $toVersion, string $actorType = 'admin', ?int $actorId = null): RelayConfigOutbox
    {
        $source = RelayConfigOutbox::where('machine_id', $machine->id)->where('version', $toVersion)->first();
        if (!$source) {
            throw new RelayValidationException('outbox version ' . $toVersion . ' not found for this machine');
        }
        return DB::transaction(function () use ($machine, $source, $actorType, $actorId) {
            $machine = ServerMachine::where('id', $machine->id)->lockForUpdate()->first() ?? $machine;
            $document = json_decode($source->payload, true);
            $version = (int) $machine->relay_config_version + 1;
            $document['version'] = $version;
            $document['generated_at'] = now()->toIso8601String();
            $document['rolled_back_from'] = (int) $source->version;
            $payload = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $hash = hash('sha256', $payload);

            RelayConfigOutbox::where('machine_id', $machine->id)
                ->where('status', RelayConfigOutbox::STATUS_PENDING)
                ->update(['status' => RelayConfigOutbox::STATUS_SUPERSEDED]);

            $outbox = RelayConfigOutbox::create([
                'machine_id' => $machine->id,
                'version' => $version,
                'config_hash' => $hash,
                'payload' => $payload,
                'status' => RelayConfigOutbox::STATUS_PENDING,
                'created_by' => $actorId,
                'created_at' => now(),
            ]);
            $machine->forceFill([
                'relay_config_version' => $version,
                'relay_config_hash' => $hash,
            ])->save();
            RelayAuditLog::record($machine->id, null, $actorType, $actorId, 'config.rollback', 'from v' . $source->version . ' republished as v' . $version);
            return $outbox;
        });
    }

    /** Latest published document for the agent pull endpoint. */
    public function latestForAgent(ServerMachine $machine): ?array
    {
        $outbox = RelayConfigOutbox::where('machine_id', $machine->id)
            ->orderByDesc('version')->first();
        if (!$outbox) {
            return null;
        }
        return [
            'version' => (int) $outbox->version,
            'hash' => $outbox->config_hash,
            'config' => json_decode($outbox->payload, true),
        ];
    }

    /**
     * Agent ACK: the machine reports which version it applied (or failed).
     * Returns false when the ACK does not match any known version.
     */
    public function handleAck(ServerMachine $machine, int $version, ?string $hash, bool $applied, ?string $error = null): bool
    {
        $outbox = RelayConfigOutbox::where('machine_id', $machine->id)->where('version', $version)->first();
        if (!$outbox) {
            return false;
        }
        if ($hash !== null && $hash !== $outbox->config_hash) {
            $machine->forceFill(['relay_last_error' => 'ack hash mismatch for v' . $version])->save();
            RelayAuditLog::record($machine->id, null, 'agent', null, 'config.ack_hash_mismatch', 'v' . $version);
            return false;
        }
        if (!$applied) {
            $machine->forceFill(['relay_last_error' => $error ?? ('agent failed to apply v' . $version)])->save();
            RelayRule::where('machine_id', $machine->id)
                ->where('config_version', $version)
                ->update(['status' => RelayRule::STATUS_UNKNOWN, 'last_error' => $error ?? 'agent apply failed']);
            RelayAuditLog::record($machine->id, null, 'agent', null, 'config.ack_failed', 'v' . $version . ' ' . ($error ?? ''));
            return true;
        }

        $outbox->forceFill(['status' => RelayConfigOutbox::STATUS_ACKED])->save();
        $isLatest = $version === (int) $machine->relay_config_version;
        $machine->forceFill([
            'relay_applied_version' => $version,
            'relay_applied_hash' => $outbox->config_hash,
            'relay_applied_at' => now(),
            'relay_last_error' => null,
        ])->save();

        if ($isLatest) {
            $document = json_decode($outbox->payload, true);
            $codes = collect($document['rules'] ?? [])->pluck('rule_code')->all();
            RelayRule::where('machine_id', $machine->id)->whereIn('rule_code', $codes)
                ->update(['status' => RelayRule::STATUS_APPLIED]);
            RelayRule::where('machine_id', $machine->id)->where('enabled', true)
                ->whereNotIn('rule_code', $codes)
                ->where('status', RelayRule::STATUS_APPLYING)
                ->update(['status' => RelayRule::STATUS_NOT_APPLIED]);
        }
        RelayAuditLog::record($machine->id, null, 'agent', null, 'config.ack', 'v' . $version);
        return true;
    }
}
