<?php

namespace App\Services\Relay;

use App\Models\RelayAuditLog;
use App\Models\RelayConfigOutbox;
use App\Models\RelayRevocation;
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
 * NODE-target rules follow their node's LANDING endpoint: the hosting
 * machine's public_address + the node's effective landing port
 * (direct_port ?? server_port), derived live at publish time. The node's
 * subscription host is an entry alias and is never used as a target. A
 * target node that was deleted, lost agent eligibility, or whose machine
 * has no public_address turns the rule BROKEN: it is excluded from the
 * document and never silently rebound to anything else.
 */
class RelayConfigService
{
    public const PROTOCOL = 'relay.config.v1';

    /**
     * TCP reachability prober for the rollback gate (B04): fn(string
     * $host, int $port, float $timeoutSeconds): bool. Null = not wired
     * (unit tests, agent channel) and the rollback gate is skipped; the
     * admin controller wires RelayTargetProbe::tcp() so production
     * rollbacks are always probed. Same injectable pattern as
     * MachineRoleService's address precheck.
     */
    public function __construct(private mixed $tcpProber = null)
    {
    }

    /**
     * Build the effective config document for a machine (no persistence).
     * Side effect: rules whose NODE target lost eligibility are marked
     * BROKEN and excluded.
     */
    public function buildDocument(ServerMachine $machine): array
    {
        $rules = [];
        if ($machine->isRelayRole() && $machine->relay_enabled && $machine->relay_status !== ServerMachine::RELAY_STATUS_DRAINING) {
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
            'relay_enabled' => (bool) ($machine->isRelayRole() && $machine->relay_enabled)
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
            // Follow the node's current LANDING endpoint (its machine's
            // public_address + effective landing port), never the node's
            // subscription host: that host is a client-facing entry alias.
            // A landing that cannot be derived (machine address cleared
            // after the rule was created) breaks the rule, same as a lost
            // eligibility — it is excluded and never silently rebound.
            [$endpoint, $endpointError] = RelayRuleService::nodeLandingEndpoint($node);
            if ($endpoint === null) {
                if ($rule->status !== RelayRule::STATUS_BROKEN) {
                    $rule->forceFill([
                        'status' => RelayRule::STATUS_BROKEN,
                        'last_error' => $endpointError,
                    ])->save();
                    RelayAuditLog::record($rule->machine_id, $rule->id, 'system', null, 'rule.broken', $rule->rule_code);
                }
                return null;
            }
            $host = $endpoint['host'];
            $port = $endpoint['port'];
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
            // R03: capture what the agent has confirmed it is serving
            // BEFORE buildDocument() rewrites statuses. Any of those
            // rules missing from the new document is a revocation the
            // agent has not confirmed yet — flag it pending until the
            // ACK of a document without it arrives (see handleAck()).
            $previouslyApplied = RelayRule::where('machine_id', $machine->id)
                ->where('status', RelayRule::STATUS_APPLIED)
                ->pluck('rule_code')->all();
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
            // R03 + B01: previously-serving rules excluded from this
            // document enter the revocation-pending window — the agent is
            // only known to have stopped serving them once it ACKs a
            // document without them. The revoked set is computed from the
            // DOCUMENTS, not only from rule-row statuses: the agent is
            // known to serve exactly the document it last ACKed as
            // applied (relay_applied_version), so any code in that
            // document but not in the new one is an unconfirmed
            // revocation — including rules whose row was flipped to
            // DISABLED before this publish ran (round 1's status-only
            // capture, kept below as a second net, missed those) and
            // rules whose row was deleted outright (no row remains to
            // carry a marker — the obligation table is the only record).
            $appliedDocCodes = [];
            if ((int) $machine->relay_applied_version > 0) {
                $appliedOutbox = RelayConfigOutbox::where('machine_id', $machine->id)
                    ->where('version', (int) $machine->relay_applied_version)->first();
                if ($appliedOutbox) {
                    $appliedDoc = json_decode($appliedOutbox->payload, true);
                    $appliedDocCodes = collect($appliedDoc['rules'] ?? [])->pluck('rule_code')->all();
                }
            }
            $revoked = array_values(array_unique(array_merge(
                array_diff($previouslyApplied, $included),
                array_diff($appliedDocCodes, $included)
            )));
            foreach ($revoked as $code) {
                $rule = RelayRule::where('machine_id', $machine->id)->where('rule_code', $code)->first();
                $reason = match (true) {
                    $rule === null => RelayRevocation::REASON_DELETED,
                    $rule->status === RelayRule::STATUS_BROKEN => RelayRevocation::REASON_BROKEN,
                    !$rule->enabled => RelayRevocation::REASON_DISABLED,
                    default => RelayRevocation::REASON_EXCLUDED,
                };
                $wasOpen = RelayRevocation::openFor($machine->id, $code) !== null;
                RelayRevocation::record($machine->id, $code, $rule?->id, $reason, $version);
                if ($rule && $rule->revocation_pending_at === null) {
                    $rule->forceFill(['revocation_pending_at' => now()])->save();
                }
                if (!$wasOpen) {
                    RelayAuditLog::record($machine->id, $rule?->id, $actorType, $actorId, 'rule.revocation_pending', $code . ' excluded from v' . $version . ' (' . $reason . '); awaiting agent ack');
                }
            }
            // B01: a rule published again closes any open obligation for
            // it — the agent serving it is once more exactly what the
            // panel wants, so the revocation premise no longer exists.
            $republished = RelayRevocation::where('machine_id', $machine->id)
                ->whereNull('confirmed_at')
                ->whereIn('rule_code', $included)
                ->get();
            if ($republished->isNotEmpty()) {
                foreach ($republished as $obligation) {
                    $obligation->forceFill([
                        'confirmed_at' => now(),
                        'confirmed_version' => $version,
                        'confirm_note' => 'rule published again in v' . $version,
                    ])->save();
                    RelayRule::where('machine_id', $machine->id)
                        ->where('rule_code', $obligation->rule_code)
                        ->whereNotNull('revocation_pending_at')
                        ->update(['revocation_pending_at' => null]);
                }
                RelayAuditLog::record($machine->id, null, $actorType, $actorId, 'rule.revocation_confirmed',
                    'republished in v' . $version . ': ' . $republished->pluck('rule_code')->implode(','));
            }
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
        // R12: a historical document is a snapshot, not a truth. Its
        // NODE targets were derived from the landing state of their
        // day — the node's machine address or landing port may have
        // moved since, and the node may have lost eligibility or been
        // deleted. Re-derive every NODE entry from CURRENT state and
        // refuse the rollback when a historical endpoint no longer
        // resolves: republishing it verbatim would resurrect a dead
        // endpoint (or a revoked rule) under a fresh version.
        $machine = $machine->fresh() ?? $machine;
        $document = $this->revalidateRollbackDocument($machine, json_decode($source->payload, true), (int) $source->version);
        // B04: re-derivation proves an endpoint is well-formed, not that
        // it answers. When a prober is wired (the admin controller wires
        // the real TCP prober), every re-derived NODE target must accept
        // a TCP connection — the same all-targets standard as the
        // address-change precheck (B03), not a rollback-only mechanism —
        // or the rollback is refused before anything is published. The
        // validation runs outside the publish transaction so a refusal
        // leaves its audit row behind; the residual race (state moving
        // between validation and publish) is milliseconds wide, and the
        // publish-side BROKEN gates plus the reconcile loop still guard
        // the fleet afterwards.
        if ($this->tcpProber !== null) {
            $endpoints = [];
            foreach (($document['rules'] ?? []) as $entry) {
                if (($entry['target_type'] ?? null) === RelayRule::TARGET_NODE) {
                    $endpoints[] = [
                        'host' => (string) $entry['target_host'],
                        'port' => (int) $entry['target_port'],
                        'label' => ($entry['rule_code'] ?? '?') . ' -> ' . $entry['target_host'] . ':' . $entry['target_port'],
                    ];
                }
            }
            $failed = RelayTargetProbe::probeEndpoints($this->tcpProber, $endpoints);
            if ($failed) {
                $labels = collect($failed)->pluck('label')->all();
                RelayAuditLog::record($machine->id, null, $actorType, $actorId, 'config.rollback_precheck_failed',
                    'from v' . $source->version . '; TCP-unreachable: ' . implode(', ', $labels));
                throw new RelayValidationException(
                    'rollback refused: re-derived target(s) unreachable at TCP level: ' . implode(', ', $labels) . '; nothing was republished'
                );
            }
        }
        return DB::transaction(function () use ($machine, $source, $document, $actorType, $actorId) {
            $machine = ServerMachine::where('id', $machine->id)->lockForUpdate()->first() ?? $machine;
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

    /**
     * R12 rollback gate: re-derive a historical document's NODE targets
     * from current state. Throws RelayValidationException — refusing the
     * rollback — when the machine is no longer a relay machine, when a
     * rule in the document no longer exists on this machine, or when a
     * NODE target's endpoint can no longer be derived (node deleted,
     * eligibility lost, landing address cleared). MANUAL entries pass
     * through untouched: their target is literal text the admin owns.
     */
    private function revalidateRollbackDocument(ServerMachine $machine, array $document, int $fromVersion): array
    {
        if (!$machine->isRelayRole()) {
            throw new RelayValidationException(
                'rollback refused: machine "' . $machine->name . '" is no longer a relay machine (role=' . $machine->role . ')'
            );
        }
        foreach (($document['rules'] ?? []) as $i => $entry) {
            $code = $entry['rule_code'] ?? null;
            $rule = $code
                ? RelayRule::where('machine_id', $machine->id)->where('rule_code', $code)->first()
                : null;
            if (!$rule) {
                throw new RelayValidationException(
                    'rollback refused: rule ' . ($code ?? '(unknown)') . ' from v' . $fromVersion . ' no longer exists on this machine; the historical ruleset cannot be restored as-is'
                );
            }
            if (($entry['target_type'] ?? null) !== RelayRule::TARGET_NODE) {
                continue;
            }
            $node = Server::find($entry['target_node_id'] ?? $rule->target_node_id);
            if (!$node || !ServerService::isAgentDeliverable($node)) {
                throw new RelayValidationException(
                    'rollback refused: rule ' . $code . ' target node ' . ($entry['target_node_id'] ?? '?')
                    . ' is deleted or no longer agent-deliverable; the v' . $fromVersion . ' endpoint cannot be re-derived'
                );
            }
            [$endpoint, $endpointError] = RelayRuleService::nodeLandingEndpoint($node);
            if ($endpoint === null) {
                throw new RelayValidationException(
                    'rollback refused: rule ' . $code . ' landing endpoint cannot be re-derived (' . $endpointError . ')'
                );
            }
            $document['rules'][$i]['target_host'] = $endpoint['host'];
            $document['rules'][$i]['target_port'] = $endpoint['port'];
        }
        return $document;
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

        $document = json_decode($outbox->payload, true);
        $codes = collect($document['rules'] ?? [])->pluck('rule_code')->all();
        if ($isLatest) {
            RelayRule::where('machine_id', $machine->id)->whereIn('rule_code', $codes)
                ->update(['status' => RelayRule::STATUS_APPLIED]);
            RelayRule::where('machine_id', $machine->id)->where('enabled', true)
                ->whereNotIn('rule_code', $codes)
                ->where('status', RelayRule::STATUS_APPLYING)
                ->update(['status' => RelayRule::STATUS_NOT_APPLIED]);
        }
        // B01: revocation confirmation is PER RULE, judged against the
        // document this ACK actually covers — never a blanket clear.
        // An obligation is confirmed only when the ACKed applied
        // document does not contain the rule AND the ACKed version is
        // at least the version that first excluded it. An ACK whose
        // document still contains the rule (e.g. a stale ACK of an
        // older version arriving late) confirms nothing about it; the
        // obligation stays open for a later, excluding ACK. This runs
        // for every applied ACK, not only the latest: a late ACK of an
        // excluding version is still the agent's proof it stopped.
        $confirmed = [];
        RelayRevocation::where('machine_id', $machine->id)->whereNull('confirmed_at')->get()
            ->each(function (RelayRevocation $obligation) use ($codes, $version, &$confirmed) {
                if (in_array($obligation->rule_code, $codes, true)) {
                    return;
                }
                if ((int) $obligation->since_version > 0 && $version < (int) $obligation->since_version) {
                    return;
                }
                $obligation->forceFill([
                    'confirmed_at' => now(),
                    'confirmed_version' => $version,
                    'confirm_note' => 'agent acked v' . $version . ' without the rule',
                ])->save();
                $confirmed[] = $obligation->rule_code;
            });
        if ($confirmed) {
            RelayRule::where('machine_id', $machine->id)->whereIn('rule_code', $confirmed)
                ->whereNotNull('revocation_pending_at')
                ->update(['revocation_pending_at' => null]);
            RelayAuditLog::record($machine->id, null, 'agent', null, 'rule.revocation_confirmed', 'v' . $version . ' acked; confirmed: ' . implode(',', $confirmed));
        }
        RelayAuditLog::record($machine->id, null, 'agent', null, 'config.ack', 'v' . $version);
        return true;
    }
}
