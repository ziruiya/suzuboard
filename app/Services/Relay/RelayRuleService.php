<?php

namespace App\Services\Relay;

use App\Models\RelayAuditLog;
use App\Models\RelayPortClaim;
use App\Models\RelayRevocation;
use App\Models\RelayRule;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\ServerService;
use Illuminate\Support\Facades\DB;

/**
 * SB-RELAY-01: rule CRUD with the full guard set —
 *  - listen ports only from the preflight pool (service layer here, model
 *    layer in RelayRule::booted, occupancy layer in suzu_relay_port_claims);
 *  - per-machine rule/connection caps from the preflight resource classes;
 *  - NODE targets must be agent-deliverable (AUD-010/011 gate reused);
 *  - MANUAL targets on a public bind need an approved CIDR allowlist;
 *  - no loops: a rule may not feed this machine's own relay listeners,
 *    directly or through a chain of node targets across machines.
 * Every mutation is audited and republishes the machine config.
 */
class RelayRuleService
{
    public function __construct(private RelayConfigService $config)
    {
    }

    public function create(ServerMachine $machine, array $data, string $actorType = 'admin', ?int $actorId = null): RelayRule
    {
        return DB::transaction(function () use ($machine, $data, $actorType, $actorId) {
            $machine = ServerMachine::whereKey($machine->id)->lockForUpdate()->firstOrFail();
            $this->assertMachineCanTakeRules($machine);
            $attrs = $this->validateAndNormalize($machine, $data, null);
            $rule = new RelayRule($attrs);
            $rule->machine_id = $machine->id;
            $rule->status = RelayRule::STATUS_NOT_APPLIED;
            $rule->save();
            $rule->forceFill(['rule_code' => 'RL' . str_pad((string) $rule->id, 6, '0', STR_PAD_LEFT)])->save();
            $this->syncClaims($rule);
            RelayAuditLog::record($machine->id, $rule->id, $actorType, $actorId, 'rule.create', $rule->rule_code . ' ' . $rule->name);
            $this->config->publish($machine, $actorType, $actorId);
            return $rule->fresh();
        });
    }

    public function update(RelayRule $rule, array $data, string $actorType = 'admin', ?int $actorId = null): RelayRule
    {
        $machine = $rule->machine;
        return DB::transaction(function () use ($rule, $machine, $data, $actorType, $actorId) {
            $machine = ServerMachine::whereKey($machine->id)->lockForUpdate()->firstOrFail();
            $rule = RelayRule::whereKey($rule->id)->lockForUpdate()->firstOrFail();
            $merged = array_merge($rule->only([
                'name', 'transport', 'listen_ip', 'listen_port', 'target_type',
                'target_node_id', 'target_host', 'target_port', 'allowlist_cidrs',
                'allowlist_approved', 'max_connections', 'udp_max_sessions',
                'udp_idle_timeout_sec', 'drain_timeout_sec', 'buffer_bytes',
                'rate_in_bps', 'rate_out_bps', 'burst_bytes', 'enabled',
            ]), $data);
            $attrs = $this->validateAndNormalize($machine, $merged, $rule);
            // B01: an update that disables a rule the agent has
            // confirmed it is serving creates the revocation obligation
            // NOW, before the status flip below erases the evidence —
            // publish()'s document diff is the second net, not the only
            // one.
            if (($attrs['enabled'] ?? true) === false && $rule->status === RelayRule::STATUS_APPLIED) {
                RelayRevocation::record($machine->id, $rule->rule_code, $rule->id, RelayRevocation::REASON_DISABLED);
            }
            $rule->fill($attrs);
            $rule->version = $rule->version + 1; // optimistic lock bump
            if ($rule->status === RelayRule::STATUS_APPLIED) {
                $rule->status = RelayRule::STATUS_NOT_APPLIED;
            }
            $rule->save();
            $this->syncClaims($rule);
            RelayAuditLog::record($machine->id, $rule->id, $actorType, $actorId, 'rule.update', $rule->rule_code);
            $this->config->publish($machine, $actorType, $actorId);
            return $rule->fresh();
        });
    }

    public function setEnabled(RelayRule $rule, bool $enabled, string $actorType = 'admin', ?int $actorId = null): RelayRule
    {
        $machine = $rule->machine;
        return DB::transaction(function () use ($machine, $rule, $enabled, $actorType, $actorId) {
            $machine = ServerMachine::whereKey($machine->id)->lockForUpdate()->firstOrFail();
            $rule = RelayRule::whereKey($rule->id)->lockForUpdate()->firstOrFail();
            if ($enabled) {
                $this->assertMachineCanTakeRules($machine);
                // Re-validate the target: a node that lost eligibility while the
                // rule was disabled must not come back (it goes BROKEN instead).
                $this->validateAndNormalize($machine, $rule->only([
                    'name', 'transport', 'listen_ip', 'listen_port', 'target_type',
                    'target_node_id', 'target_host', 'target_port', 'allowlist_cidrs',
                    'allowlist_approved', 'max_connections', 'udp_max_sessions',
                ]), $rule);
                $this->assertClaimsAvailable($rule);
            }
            // B01 (GPT round 2, reproduced defect): capture the revocation
            // obligation BEFORE the status flips to DISABLED. Round 1's
            // publish-time capture queried status=APPLIED *after* this flip
            // had already run, so a disabled-while-serving rule never
            // entered the pending window at all — the panel assumed the
            // agent stopped serving it, with no record anywhere.
            if (!$enabled && $rule->status === RelayRule::STATUS_APPLIED) {
                RelayRevocation::record($machine->id, $rule->rule_code, $rule->id, RelayRevocation::REASON_DISABLED);
                $rule->forceFill(['revocation_pending_at' => now()])->save();
            }
            $rule->forceFill([
                'enabled' => $enabled,
                'status' => $enabled ? RelayRule::STATUS_NOT_APPLIED : RelayRule::STATUS_DISABLED,
            ])->save();
            RelayAuditLog::record($machine->id, $rule->id, $actorType, $actorId, $enabled ? 'rule.enable' : 'rule.disable', $rule->rule_code);
            $this->config->publish($machine, $actorType, $actorId);
            return $rule->fresh();
        });
    }

    public function delete(RelayRule $rule, string $actorType = 'admin', ?int $actorId = null): void
    {
        $machine = $rule->machine;
        DB::transaction(function () use ($rule, $machine, $actorType, $actorId) {
            $machine = ServerMachine::whereKey($machine->id)->lockForUpdate()->firstOrFail();
            $rule = RelayRule::whereKey($rule->id)->lockForUpdate()->firstOrFail();
            // B01: the obligation is recorded BEFORE the row goes away —
            // it lives in its own table precisely so this delete cannot
            // take the "agent has not confirmed it stopped" fact with
            // it. publish() below cannot capture it either (the row is
            // gone), which is why round 1 lost deleted rules entirely;
            // the publish-time document diff still sees the code vanish
            // from the document and keeps this same obligation fresh.
            if ($rule->status === RelayRule::STATUS_APPLIED) {
                RelayRevocation::record($machine->id, $rule->rule_code, $rule->id, RelayRevocation::REASON_DELETED);
            }
            RelayPortClaim::where('rule_id', $rule->id)->delete();
            RelayAuditLog::record($machine->id, $rule->id, $actorType, $actorId, 'rule.delete', $rule->rule_code . ' ' . $rule->name);
            if ($rule->rule_code !== null) {
                DB::table('suzu_relay_rule_tombstones')->insert([
                    'machine_id' => $machine->id,
                    'rule_code' => $rule->rule_code,
                    'rule_id' => $rule->id,
                    'deleted_at' => now()->timestamp,
                ]);
            }
            if (!$rule->delete()) {
                throw new \RuntimeException('Relay rule deletion failed');
            }
            $this->config->publish($machine, $actorType, $actorId);
        });
    }

    private function assertMachineCanTakeRules(ServerMachine $machine): void
    {
        if (!$machine->isRelayRole()) {
            throw new RelayValidationException(
                'machine "' . $machine->name . '" is a landing machine (role=landing); only relay-role machines can hold relay rules'
            );
        }
        if (!$machine->relay_enabled || $machine->relay_status === ServerMachine::RELAY_STATUS_DRAINING) {
            throw new RelayValidationException('machine is not an active relay machine');
        }
        $count = RelayRule::where('machine_id', $machine->id)->count();
        if ($count >= $machine->relayRulesLimit()) {
            throw new RelayValidationException(
                'machine rule limit reached (' . $machine->relayRulesLimit() . ' for tier ' . $machine->relay_tier . ')'
            );
        }
    }

    /**
     * Validate a candidate rule and return the attributes to persist.
     * $existing is the rule being updated (excluded from claim/loop checks).
     */
    private function validateAndNormalize(ServerMachine $machine, array $data, ?RelayRule $existing): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new RelayValidationException('name is required');
        }
        $transport = strtolower((string) ($data['transport'] ?? 'both'));
        if (!in_array($transport, ['tcp', 'udp', 'both'], true)) {
            throw new RelayValidationException('transport must be tcp, udp or both');
        }
        $listenIp = trim((string) ($data['listen_ip'] ?? '0.0.0.0'));
        if (filter_var($listenIp, FILTER_VALIDATE_IP) === false) {
            throw new RelayValidationException('listen_ip must be a valid IP address');
        }
        $listenIp = $this->normalizeBindIp($listenIp);
        $listenPort = (int) ($data['listen_port'] ?? 0);
        if (!RelayPorts::inPool($listenPort)) {
            throw new RelayValidationException(
                'listen_port must be inside the relay pool ' . RelayPorts::POOL_MIN . '-' . RelayPorts::POOL_MAX
            );
        }

        $targetType = strtoupper((string) ($data['target_type'] ?? ''));
        $targetNodeId = null;
        $targetHost = '';
        $targetPort = 0;
        $contractHash = null;
        // target_resolve_mode is narrowed to its single live meaning:
        // 'auto' — the agent dials the published host, resolving hostnames
        // per connection. Anything else is rejected instead of being
        // silently stored as a dead value.
        $resolveMode = strtolower(trim((string) ($data['target_resolve_mode'] ?? 'auto')));
        if ($resolveMode !== 'auto') {
            throw new RelayValidationException('target_resolve_mode must be "auto" (the only supported mode)');
        }

        if ($targetType === RelayRule::TARGET_NODE) {
            $targetNodeId = (int) ($data['target_node_id'] ?? 0);
            $node = Server::find($targetNodeId);
            if (!$node) {
                throw new RelayValidationException('target node does not exist');
            }
            if (!ServerService::isAgentDeliverable($node)) {
                throw new RelayValidationException('target node is not agent-deliverable (disabled, hidden stock node, or non-production profile)');
            }
            if ($node->machine_id && (int) $node->machine_id === (int) $machine->id) {
                throw new RelayValidationException(
                    'target node is hosted on this relay machine itself; machine roles are exclusive (landing or relay, never both), so a relay rule can never target a node on its own machine'
                );
            }
            // The target is the node's LANDING endpoint — its machine's
            // public address + the node's effective landing port — never
            // the node's subscription host (an entry alias) and never the
            // raw server_port of a front-proxied node.
            [$endpoint, $endpointError] = self::nodeLandingEndpoint($node);
            if ($endpoint === null) {
                throw new RelayValidationException($endpointError);
            }
            $targetHost = $endpoint['host'];
            $targetPort = $endpoint['port'];
            $contractHash = self::nodeContractHash($node);
        } elseif ($targetType === RelayRule::TARGET_MANUAL) {
            $targetHost = trim((string) ($data['target_host'] ?? ''));
            $targetPort = (int) ($data['target_port'] ?? 0);
            if ($targetHost === '' || $targetPort < 1 || $targetPort > 65535) {
                throw new RelayValidationException('manual target requires a host and a valid port');
            }
            if (filter_var($targetHost, FILTER_VALIDATE_IP) === false
                && !preg_match('/^(?=.{1,253}$)([a-zA-Z0-9-]+\.)*[a-zA-Z0-9-]+$/', $targetHost)) {
                throw new RelayValidationException('manual target host must be an IP or a hostname');
            }
        } else {
            throw new RelayValidationException('target_type must be NODE or MANUAL');
        }

        // MANUAL targets on a public bind: approved CIDR allowlist required.
        $allowlist = $data['allowlist_cidrs'] ?? null;
        if (is_string($allowlist)) {
            $allowlist = array_values(array_filter(array_map('trim', explode(',', $allowlist))));
        }
        $allowlistApproved = (bool) ($data['allowlist_approved'] ?? false);
        if ($targetType === RelayRule::TARGET_MANUAL && $this->isPublicBind($listenIp)) {
            if (empty($allowlist)) {
                throw new RelayValidationException('manual target on a public bind requires an allowlist of CIDRs');
            }
            if (!$allowlistApproved) {
                throw new RelayValidationException('manual target allowlist must be approved');
            }
        }
        foreach ((array) ($allowlist ?? []) as $cidr) {
            if (!self::isCidr((string) $cidr)) {
                throw new RelayValidationException('invalid CIDR in allowlist: ' . $cidr);
            }
        }

        // Connection caps follow the machine's preflight class unless the
        // admin explicitly set tighter values on the rule.
        $maxConnections = (int) ($data['max_connections'] ?? 0);
        if ($maxConnections <= 0) {
            $maxConnections = $machine->relayTcpLimit();
        }
        if ($maxConnections > $machine->relayTcpLimit()) {
            throw new RelayValidationException('max_connections exceeds the machine tier cap (' . $machine->relayTcpLimit() . ')');
        }
        $udpSessions = (int) ($data['udp_max_sessions'] ?? 0);
        if ($udpSessions <= 0) {
            $udpSessions = $machine->relayUdpLimit();
        }
        if ($udpSessions > $machine->relayUdpLimit()) {
            throw new RelayValidationException('udp_max_sessions exceeds the machine tier cap (' . $machine->relayUdpLimit() . ')');
        }

        $candidate = [
            'name' => $name,
            'transport' => $transport,
            'listen_ip' => $listenIp,
            'listen_port' => $listenPort,
            'target_type' => $targetType,
            'target_node_id' => $targetNodeId,
            'target_host' => $targetHost,
            'target_port' => $targetPort,
            'target_contract_hash' => $contractHash,
            'target_resolve_mode' => 'auto',
            'target_snapshot_host' => $targetType === RelayRule::TARGET_NODE ? $targetHost : null,
            'target_snapshot_port' => $targetType === RelayRule::TARGET_NODE ? $targetPort : null,
            'allowlist_cidrs' => $allowlist ?: null,
            'allowlist_approved' => $allowlistApproved,
            'max_connections' => $maxConnections,
            'udp_max_sessions' => $udpSessions,
            'udp_idle_timeout_sec' => max(1, (int) ($data['udp_idle_timeout_sec'] ?? 60)),
            'drain_timeout_sec' => max(1, (int) ($data['drain_timeout_sec'] ?? 300)),
            'buffer_bytes' => max(1024, (int) ($data['buffer_bytes'] ?? 32768)),
            'rate_in_bps' => isset($data['rate_in_bps']) && $data['rate_in_bps'] !== null ? (int) $data['rate_in_bps'] : null,
            'rate_out_bps' => isset($data['rate_out_bps']) && $data['rate_out_bps'] !== null ? (int) $data['rate_out_bps'] : null,
            'burst_bytes' => isset($data['burst_bytes']) && $data['burst_bytes'] !== null ? (int) $data['burst_bytes'] : null,
            'enabled' => array_key_exists('enabled', $data) ? (bool) $data['enabled'] : true,
        ];

        $this->assertNoLoop($machine, $candidate, $existing);

        return $candidate;
    }

    /**
     * Loop prevention, two layers:
     *  1. Direct/chain on this machine: the target may not be one of this
     *     machine's own relay listen endpoints — following listen ports as
     *     edges must never lead back to the candidate's own listen port.
     *  2. Cross-machine: NODE targets form machine->machine edges; adding
     *     this edge must not close a cycle.
     */
    private function assertNoLoop(ServerMachine $machine, array $candidate, ?RelayRule $existing): void
    {
        $ownRules = RelayRule::where('machine_id', $machine->id)
            ->when($existing, fn($q) => $q->where('id', '!=', $existing->id))
            ->get();

        // Resolve the candidate's effective target endpoint.
        [$tHost, $tPort] = [$candidate['target_host'], $candidate['target_port']];
        $targetMachineId = null;
        if ($candidate['target_type'] === RelayRule::TARGET_NODE && $candidate['target_node_id']) {
            $node = Server::find($candidate['target_node_id']);
            $targetMachineId = $node?->machine_id ? (int) $node->machine_id : null;
        }

        $pointsHome = $this->hostPointsAtMachine($machine, $tHost, $targetMachineId);
        if ($pointsHome) {
            if ($tPort === (int) $candidate['listen_port']) {
                throw new RelayValidationException('target is this rule\'s own listen endpoint (relay loop)');
            }
            // Walk the chain of this machine's listeners starting at tPort:
            // if following targets ever lands back on the candidate's own
            // listen port (or revisits a port), the chain is a loop.
            $edges = [];
            foreach ($ownRules as $r) {
                $edges[(int) $r->listen_port] = $r;
            }
            $seen = [];
            $port = $tPort;
            while (isset($edges[$port])) {
                if (isset($seen[$port])) {
                    throw new RelayValidationException('target creates a relay loop on this machine');
                }
                $seen[$port] = true;
                $next = $edges[$port];
                if ($next->target_type === RelayRule::TARGET_NODE) {
                    break; // chain leaves via a node; cross-machine check below covers cycles
                }
                if (!$this->hostPointsAtMachine($machine, (string) $next->target_host, null)) {
                    break;
                }
                $port = (int) $next->target_port;
                if ($port === (int) $candidate['listen_port']) {
                    throw new RelayValidationException('target creates a relay loop on this machine');
                }
            }
        }

        // Cross-machine cycle: edges machine -> target node's machine.
        if ($targetMachineId !== null) {
            if ($targetMachineId === (int) $machine->id) {
                // Node on the same machine: fine unless its port is one of
                // this machine's relay listeners (handled above via chain).
                return;
            }
            $graph = [];
            RelayRule::where('target_type', RelayRule::TARGET_NODE)
                ->whereNotNull('target_node_id')
                ->when($existing, fn($q) => $q->where('id', '!=', $existing->id))
                ->get()
                ->each(function (RelayRule $r) use (&$graph) {
                    $node = Server::find($r->target_node_id);
                    if ($node && $node->machine_id) {
                        $graph[(int) $r->machine_id][] = (int) $node->machine_id;
                    }
                });
            $graph[(int) $machine->id][] = $targetMachineId;
            if ($this->graphHasCycleFrom($graph, $targetMachineId, (int) $machine->id)) {
                throw new RelayValidationException('target creates a cross-machine relay loop');
            }
        }
    }

    private function graphHasCycleFrom(array $graph, int $from, int $seek): bool
    {
        $stack = [$from];
        $seen = [];
        while ($stack) {
            $cur = array_pop($stack);
            if ($cur === $seek) {
                return true;
            }
            if (isset($seen[$cur])) {
                continue;
            }
            $seen[$cur] = true;
            foreach ($graph[$cur] ?? [] as $next) {
                $stack[] = $next;
            }
        }
        return false;
    }

    /**
     * Does this host refer to the relay machine itself? Loopback always
     * does; a NODE target does when the node lives on this machine; a
     * hostname does when it is the host of one of this machine's nodes.
     */
    private function hostPointsAtMachine(ServerMachine $machine, string $host, ?int $targetMachineId): bool
    {
        if (in_array(strtolower($host), ['127.0.0.1', '::1', 'localhost', '0.0.0.0', '::'], true)) {
            return true;
        }
        if ($targetMachineId !== null) {
            return $targetMachineId === (int) $machine->id;
        }
        return Server::where('machine_id', $machine->id)->where('host', $host)->exists();
    }

    private function isPublicBind(string $listenIp): bool
    {
        if ($listenIp === '0.0.0.0' || $listenIp === '::') {
            return true;
        }
        $ip = $listenIp;
        return !(
            str_starts_with($ip, '127.') || $ip === '::1'
            || str_starts_with($ip, '10.')
            || str_starts_with($ip, '192.168.')
            || preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $ip)
            || str_starts_with(strtolower($ip), 'fe80:')
            || str_starts_with(strtolower($ip), 'fd')
            || str_starts_with(strtolower($ip), 'fc')
        );
    }

    private static function isCidr(string $cidr): bool
    {
        if (!str_contains($cidr, '/')) {
            return filter_var($cidr, FILTER_VALIDATE_IP) !== false;
        }
        [$ip, $bits] = explode('/', $cidr, 2);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false || !is_numeric($bits)) {
            return false;
        }
        $max = str_contains($ip, ':') ? 128 : 32;
        return (int) $bits >= 0 && (int) $bits <= $max;
    }

    /**
     * The landing endpoint a NODE relay target actually dials:
     * [ {host, port} | null, error | null ].
     *
     * host = the public_address of the machine hosting the node (the
     * panel's authoritative machine address — the node's own host is a
     * client-facing entry alias and is never a landing address);
     * port = direct_port ?? server_port (direct_port covers front-proxied
     * nodes whose server_port is a loopback-only backend port).
     */
    public static function nodeLandingEndpoint(Server $node): array
    {
        if (!$node->machine_id) {
            return [null, 'target node is not hosted on any machine; NODE relay targets require a machine-hosted node'];
        }
        $machine = $node->relationLoaded('machine')
            ? $node->machine
            : ServerMachine::find($node->machine_id);
        if (!$machine) {
            return [null, 'target node\'s machine no longer exists'];
        }
        $address = trim((string) ($machine->public_address ?? ''));
        if ($address === '') {
            return [null, 'target machine "' . $machine->name . '" has no public_address configured; set it in server management before a relay rule can target its nodes'];
        }
        // Strict null semantics (GPT review R07): direct_port is an
        // override that is either absent (null) or a real port. A stored
        // 0 is NOT "unset" — the old falsy ?: silently fell back to
        // server_port and masked a corrupt value; now it fails the range
        // check below like any other invalid port. Writes are validated
        // to 1-65535 (ServerSave), so a 0 here means legacy/bad data and
        // must surface, not be papered over.
        $port = (int) ($node->direct_port !== null ? $node->direct_port : $node->server_port);
        if ($port < 1 || $port > 65535) {
            return [null, 'target node has no valid landing port (direct_port/server_port)'];
        }
        return [['host' => $address, 'port' => $port], null];
    }

    /**
     * Contract hash over the DERIVED landing endpoint plus the node's
     * eligibility-derived state (GPT review R08): it changes exactly when
     * the published target would change (machine address, effective
     * landing port, hosting machine) OR when the node's agent-deliverable
     * qualification changes for any reason — enabled/show, Suzu profile
     * rollout stage, enterprise-stock registration, or the deliverable
     * verdict itself. The reconcile loop compares this hash against the
     * stored snapshot and republishes on drift, so a qualification change
     * can never leave a stale contract silently in place. The node's
     * subscription host is deliberately NOT part of it.
     */
    public static function nodeContractHash(Server $node): string
    {
        [$endpoint] = self::nodeLandingEndpoint($node);
        $profile = \App\Models\SuzuNodeProfile::where('server_id', $node->id)->first();
        return hash('sha256', implode('|', [
            $node->id, $node->type,
            $node->machine_id ?? '',
            $endpoint['host'] ?? '', $endpoint['port'] ?? '',
            $node->enabled ? '1' : '0', $node->show ? '1' : '0',
            $profile?->rollout_stage ?? '',
            ServerService::isEnterpriseInventoryNode($node) ? '1' : '0',
            ServerService::isAgentDeliverable($node) ? '1' : '0',
        ]));
    }

    private function normalizeBindIp(string $ip): string
    {
        $binary = inet_pton($ip);
        if (strlen($binary) === 16 && substr($binary, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $binary = substr($binary, 12);
        }

        return inet_ntop($binary);
    }

    private function bindsOverlap(string $first, string $second): bool
    {
        $first = $this->normalizeBindIp($first);
        $second = $this->normalizeBindIp($second);
        // The agent's tcp/udp listeners can cover both families for either wildcard.
        $wildcards = ['0.0.0.0', '::'];

        return $first === $second || in_array($first, $wildcards, true) || in_array($second, $wildcards, true);
    }

    private function assertClaimsAvailable(RelayRule $rule): void
    {
        $claims = RelayPortClaim::where('machine_id', $rule->machine_id)
            ->where('listen_port', $rule->listen_port)
            ->whereIn('transport', $rule->transports())
            ->where('rule_id', '!=', $rule->id)
            ->lockForUpdate()->get();
        foreach ($claims as $claim) {
            if ($this->bindsOverlap($rule->listen_ip, $claim->bind_ip)) {
                throw new RelayValidationException(
                    'listen port ' . $rule->listen_port . '/' . $claim->transport . ' on ' . $rule->listen_ip . ' is already claimed on this machine'
                );
            }
        }
    }

    private function syncClaims(RelayRule $rule): void
    {
        $this->assertClaimsAvailable($rule);
        RelayPortClaim::where('rule_id', $rule->id)->delete();
        foreach ($rule->transports() as $transport) {
            try {
                RelayPortClaim::create([
                    'machine_id' => $rule->machine_id,
                    'rule_id' => $rule->id,
                    'transport' => $transport,
                    'bind_family' => $rule->bindFamily(),
                    'bind_ip' => $rule->listen_ip,
                    'listen_port' => $rule->listen_port,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                $rule->forceFill(['status' => RelayRule::STATUS_CONFLICT, 'last_error' => 'port claim conflict'])->save();
                throw new RelayValidationException(
                    'listen port ' . $rule->listen_port . '/' . $transport . ' on ' . $rule->listen_ip . ' is already claimed on this machine'
                );
            }
        }
    }
}
