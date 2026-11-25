<?php

namespace App\Services\Relay;

use App\Models\RelayAddressChange;
use App\Models\RelayAuditLog;
use App\Models\RelayConfigOutbox;
use App\Models\RelayRule;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\ServerService;
use Illuminate\Support\Facades\DB;

/**
 * Machine roles (owner ruling 2026-11-25): a machine is a landing machine
 * OR a relay machine, never both. `v2_server_machine.role` is the single
 * authority; this service is the only write path for role changes and for
 * the machine's public landing address.
 *
 * Role switches with existing stock are REFUSED with the cleanup path in
 * the message — never silently cascaded:
 *  - to relay:   the machine must host no nodes;
 *  - to landing: the machine must have no relay rules and its relay must
 *                already be disabled (drain/stop it first).
 */
class MachineRoleService
{
    /**
     * TCP reachability prober for the public-address precheck:
     * fn(string $host, int $port, float $timeoutSeconds): bool.
     * Injectable for tests; defaults to a real fsockopen probe.
     */
    private mixed $tcpProber;

    public function __construct(private RelayConfigService $config, mixed $tcpProber = null)
    {
        $this->tcpProber = $tcpProber ?? RelayTargetProbe::tcp();
    }

    public function setRole(ServerMachine $machine, string $role, string $actorType = 'admin', ?int $actorId = null): ServerMachine
    {
        if (!in_array($role, [ServerMachine::ROLE_LANDING, ServerMachine::ROLE_RELAY], true)) {
            throw new RelayValidationException('role must be landing or relay');
        }

        // R05: the stock checks and the role write must be atomic with
        // respect to node assignment. Both sides serialize on the
        // machine row: setRole re-reads the machine under lockForUpdate
        // and counts stock on the locked copy, and every assignment path
        // takes the same lock before writing v2_server.machine_id (see
        // assertAssignableLocked()). A stale in-memory model can never
        // smuggle a switch past a concurrent assignment — exactly one
        // side wins, the other is refused.
        return DB::transaction(function () use ($machine, $role, $actorType, $actorId) {
            $machine = ServerMachine::where('id', $machine->id)->lockForUpdate()->first() ?? $machine;
            if ($machine->role === $role) {
                return $machine;
            }

            if ($role === ServerMachine::ROLE_RELAY) {
                $nodes = Server::where('machine_id', $machine->id)->count();
                if ($nodes > 0) {
                    throw new RelayValidationException(
                        'cannot set role=relay: this machine still hosts ' . $nodes . ' node(s). '
                        . 'Unassign or delete its nodes in node management first — a machine is either a landing machine or a relay machine, never both.'
                    );
                }
            } else {
                $rules = RelayRule::where('machine_id', $machine->id)->count();
                if ($rules > 0) {
                    throw new RelayValidationException(
                        'cannot set role=landing: this machine still has ' . $rules . ' relay rule(s). '
                        . 'Delete them in relay management first — a machine is either a landing machine or a relay machine, never both.'
                    );
                }
                if ($machine->relay_enabled || $machine->relay_status !== ServerMachine::RELAY_STATUS_DISABLED) {
                    throw new RelayValidationException(
                        'cannot set role=landing: the relay on this machine is still active. Disable the relay first, then change the role.'
                    );
                }
            }

            $from = $machine->role;
            $machine->forceFill(['role' => $role])->save();
            RelayAuditLog::record($machine->id, null, $actorType, $actorId, 'machine.role', $from . ' -> ' . $role);
            return $machine->fresh();
        });
    }

    /**
     * R05 locked assignment guard: call INSIDE a transaction on every
     * write path that sets v2_server.machine_id. Takes the machine row
     * lock (serializing against setRole's locked stock check) and throws
     * when the machine is a relay machine. nodeAssignmentError() remains
     * the cheap unlocked pre-check for early, friendly failures; this is
     * the race-safe gate.
     */
    public static function assertAssignableLocked(?int $machineId): void
    {
        if (!$machineId) {
            return;
        }
        $machine = ServerMachine::where('id', $machineId)->lockForUpdate()->first();
        if ($machine && $machine->isRelayRole()) {
            throw new RelayValidationException(
                'machine "' . $machine->name . '" is a relay machine and cannot host nodes (machine roles are exclusive: landing or relay, never both)'
            );
        }
    }

    /**
     * Node-assignment guard used by every admin write path that can set
     * v2_server.machine_id. Returns an error message, or null when the
     * assignment is allowed.
     */
    public static function nodeAssignmentError(?int $machineId): ?string
    {
        if (!$machineId) {
            return null;
        }
        $machine = ServerMachine::find($machineId);
        if ($machine && $machine->isRelayRole()) {
            return 'machine "' . $machine->name . '" is a relay machine and cannot host nodes (machine roles are exclusive: landing or relay, never both)';
        }
        return null;
    }

    /**
     * Maintain the machine's authoritative public landing address. When it
     * changes, every relay machine holding NODE rules that target nodes on
     * this machine republishes, so the derived targets follow the address
     * automatically.
     */
    public function setPublicAddress(ServerMachine $machine, ?string $address, string $actorType = 'admin', ?int $actorId = null): ServerMachine
    {
        return $this->setPublicAddressWithReport($machine, $address, $actorType, $actorId)['machine'];
    }

    /**
     * R02 + R11 + B03: an address change is a fleet-wide retarget, so it
     * is no longer fire-and-forget:
     *
     *  - Precheck (R02, hardened by B03): before anything is saved, the
     *    NEW address must answer on EVERY effective landing port this
     *    change will retarget (TCP connect probe). Round 1 sampled at
     *    most five ports and let one answer pass the whole change —
     *    GPT round 2 reproduced a change where seven of eight targets
     *    were dead and it still went through. One dead affected port
     *    now refuses the whole change; the old address stays in force.
     *  - Per-follower accounting (R11): each follower relay machine is
     *    published independently; the outcome is persisted per machine
     *    in the audit log (machine.public_address.follow, published vN /
     *    FAILED: reason) and returned in the report.
     *  - Durable recovery (B03, completed by N02): the change is also
     *    recorded as a RelayAddressChange with per-follower state
     *    (published version / failure / attempts / ACK convergence). A
     *    follower that never got published is no longer just an audit
     *    line: resumeAddressChange() — driven by the reconcile loop and
     *    the admin API — retries pending and failed followers alike
     *    until every follower is confirmed under the content standard
     *    in refreshChangeStatus(), and the change completes.
     *
     * @return array{machine: ServerMachine, followers: array<int, array{machine_id: int, status: string, version?: int, error?: string}>, precheck: ?array, address_change_id: int, address_change_status: string}
     */
    public function setPublicAddressWithReport(ServerMachine $machine, ?string $address, string $actorType = 'admin', ?int $actorId = null): array
    {
        $address = $address !== null ? trim($address) : null;
        if ($address === '') {
            $address = null;
        }
        if ($address !== null && !self::isValidPublicAddress($address)) {
            throw new RelayValidationException('public_address must be an IP address or a hostname');
        }
        if ($machine->public_address === $address) {
            return ['machine' => $machine, 'followers' => []];
        }

        $followerIds = RelayRule::where('target_type', RelayRule::TARGET_NODE)
            ->whereIn('target_node_id', Server::where('machine_id', $machine->id)->pluck('id'))
            ->distinct()
            ->pluck('machine_id')
            ->map(fn($id) => (int) $id)
            ->all();

        $precheck = null;
        if ($address !== null && $followerIds) {
            $precheck = $this->precheckReachable($machine, $address, $followerIds, $actorType, $actorId);
        }

        $machine->forceFill(['public_address' => $address])->save();
        RelayAuditLog::record($machine->id, null, $actorType, $actorId, 'machine.public_address', $address ?? '(cleared)');

        $change = RelayAddressChange::create([
            'machine_id' => $machine->id,
            'address' => $address,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'status' => RelayAddressChange::STATUS_IN_PROGRESS,
            'followers' => collect($followerIds)
                ->mapWithKeys(fn (int $id) => [$id => ['status' => 'pending', 'attempts' => 0]])
                ->all(),
            'precheck' => $precheck,
        ]);

        $followers = [];
        foreach ($followerIds as $relayMachineId) {
            $relayMachine = ServerMachine::find($relayMachineId);
            if (!$relayMachine) {
                $followers[] = ['machine_id' => $relayMachineId, 'status' => 'failed', 'error' => 'relay machine no longer exists'];
                $this->noteFollowerOutcome($change, $relayMachineId, ['status' => 'failed', 'error' => 'relay machine no longer exists', 'attempts' => 1]);
                RelayAuditLog::record($relayMachineId, null, $actorType, $actorId, 'machine.public_address.follow', 'FAILED: relay machine no longer exists (address change on machine ' . $machine->id . ')');
                continue;
            }
            try {
                $outbox = $this->config->publish($relayMachine, $actorType, $actorId);
                $followers[] = ['machine_id' => $relayMachineId, 'status' => 'published', 'version' => (int) $outbox->version];
                $this->noteFollowerOutcome($change, $relayMachineId, ['status' => 'published', 'version' => (int) $outbox->version, 'error' => null, 'attempts' => 1]);
                RelayAuditLog::record($relayMachineId, null, $actorType, $actorId, 'machine.public_address.follow', 'published v' . $outbox->version . ' (address change on machine ' . $machine->id . ')');
            } catch (\Throwable $e) {
                $followers[] = ['machine_id' => $relayMachineId, 'status' => 'failed', 'error' => $e->getMessage()];
                $this->noteFollowerOutcome($change, $relayMachineId, ['status' => 'failed', 'error' => $e->getMessage(), 'attempts' => 1]);
                RelayAuditLog::record($relayMachineId, null, $actorType, $actorId, 'machine.public_address.follow', 'FAILED: ' . $e->getMessage() . ' (address change on machine ' . $machine->id . '; machine keeps its previous published config)');
            }
        }
        $this->refreshChangeStatus($change);
        return [
            'machine' => $machine->fresh(),
            'followers' => $followers,
            'precheck' => $precheck,
            'address_change_id' => $change->id,
            'address_change_status' => $change->fresh()->status,
        ];
    }

    /**
     * B03 + N02: resume an address change — retry every follower that
     * has not been published yet, then re-check ACK convergence for the
     * rest. Safe to run repeatedly (the reconcile loop runs it for every
     * open change; the admin API exposes it for manual runs).
     *
     * N02 (GPT round 3): round 2 retried only followers marked
     * `failed`. A follower still `pending` — the record was created and
     * the process died before the publish loop reached it (crash,
     * container restart) — was skipped forever and the change could
     * never converge. `pending` is a resumable state. Both the first
     * run and a resume are idempotent: before publishing, a follower
     * whose latest published document already equals the document that
     * would be derived now is recognised as done (an interrupted
     * publish that did land, or an unrelated republish that already
     * carried the new address) and is NOT published again — repeated
     * resumes must not inflate the machine's config version.
     *
     * Convergence itself is judged by refreshChangeStatus() under the
     * content standard documented there, not by version numbers alone.
     *
     * N02-1 (GPT round 4): the idempotent adoption is bound to THIS
     * change's target address. Before an unpublished follower is
     * adopted or published, the change must still own the landing
     * machine's effective address; a change overtaken by a newer one
     * closes its unpublished followers as `superseded` and publishes
     * and adopts nothing — an address that was never published for a
     * change must never be announced as that change's work.
     */
    public function resumeAddressChange(RelayAddressChange $change, string $actorType = 'system', ?int $actorId = null): RelayAddressChange
    {
        $landingMachine = ServerMachine::find($change->machine_id);
        foreach (($change->followers ?? []) as $relayMachineId => $state) {
            $followerStatus = $state['status'] ?? '';
            if ($followerStatus !== 'failed' && $followerStatus !== 'pending') {
                continue;
            }
            $relayMachineId = (int) $relayMachineId;
            $relayMachine = ServerMachine::find($relayMachineId);
            if (!$relayMachine) {
                $this->noteFollowerOutcome($change, $relayMachineId, ['status' => 'failed', 'error' => 'relay machine no longer exists', 'attempts' => (int) ($state['attempts'] ?? 0) + 1]);
                continue;
            }
            // N02-1 ownership guard: publishedVersionCoveringCurrent
            // Document() compares the follower's latest published
            // document with the document derived from CURRENT state —
            // it never asks whether that document belongs to this
            // change. Under consecutive changes X -> A -> B, change #1
            // (A, still pending) would adopt B's published version and
            // be confirmed by B's ACK although A was never published.
            // The adoption — and any fresh publish under this change's
            // name — is only legitimate while this change's address is
            // still the machine's effective address, i.e. while the
            // current document genuinely embodies THIS change's
            // retarget. Otherwise the follower is superseded, exactly
            // like a published follower overtaken by a newer change.
            if ($landingMachine && $landingMachine->public_address !== $change->address) {
                $this->noteFollowerOutcome($change, $relayMachineId, [
                    'status' => 'superseded',
                    'error' => null,
                    'attempts' => (int) ($state['attempts'] ?? 0),
                    'note' => 'superseded before publish: machine ' . $change->machine_id . ' is now at '
                        . ($landingMachine->public_address ?? '(cleared)') . '; this change targeted '
                        . ($change->address ?? '(cleared)') . ' and never published it',
                ]);
                RelayAuditLog::record($relayMachineId, null, $actorType, $actorId, 'machine.public_address.follow',
                    'superseded: address change #' . $change->id . ' on machine ' . $change->machine_id
                    . ' targeted ' . ($change->address ?? '(cleared)') . ' but the machine is now at '
                    . ($landingMachine->public_address ?? '(cleared)') . '; nothing published or adopted for the stale change');
                continue;
            }
            $alreadyPublished = $this->publishedVersionCoveringCurrentDocument($relayMachine);
            if ($alreadyPublished !== null) {
                $this->noteFollowerOutcome($change, $relayMachineId, [
                    'status' => 'published',
                    'version' => $alreadyPublished,
                    'error' => null,
                    'attempts' => (int) ($state['attempts'] ?? 0),
                    'note' => 'already published as v' . $alreadyPublished . '; resume recognised it, no republish',
                ]);
                RelayAuditLog::record($relayMachineId, null, $actorType, $actorId, 'machine.public_address.follow', 'already at v' . $alreadyPublished . ' (address change #' . $change->id . ' on machine ' . $change->machine_id . ' resumed; no republish needed)');
                continue;
            }
            $attempts = (int) ($state['attempts'] ?? 0) + 1;
            try {
                $outbox = $this->config->publish($relayMachine, $actorType, $actorId);
                $this->noteFollowerOutcome($change, $relayMachineId, ['status' => 'published', 'version' => (int) $outbox->version, 'error' => null, 'attempts' => $attempts]);
                RelayAuditLog::record($relayMachineId, null, $actorType, $actorId, 'machine.public_address.follow', 'published v' . $outbox->version . ' (address change #' . $change->id . ' on machine ' . $change->machine_id . ' resumed)');
            } catch (\Throwable $e) {
                $this->noteFollowerOutcome($change, $relayMachineId, ['status' => 'failed', 'error' => $e->getMessage(), 'attempts' => $attempts]);
                RelayAuditLog::record($relayMachineId, null, $actorType, $actorId, 'machine.public_address.follow', 'FAILED: ' . $e->getMessage() . ' (address change #' . $change->id . ' on machine ' . $change->machine_id . ' resumed)');
            }
        }
        $change = $change->fresh();
        $this->refreshChangeStatus($change);
        $change = $change->fresh();
        RelayAuditLog::record($change->machine_id, null, $actorType, $actorId, 'machine.public_address.resume', 'address change #' . $change->id . ' -> ' . $change->status);
        return $change;
    }

    /** Merge one follower's outcome into the change record. */
    private function noteFollowerOutcome(RelayAddressChange $change, int $relayMachineId, array $outcome): void
    {
        $followers = $change->followers ?? [];
        $followers[$relayMachineId] = array_merge($followers[$relayMachineId] ?? [], $outcome, [
            'updated_at' => now()->toIso8601String(),
        ]);
        $change->forceFill(['followers' => $followers])->save();
    }

    /**
     * Recompute a change's status from its followers.
     *
     * N02 confirmation standard (final definition, GPT round 3): a
     * published follower is `confirmed` for THIS change only when the
     * document the agent has actually applied embodies this change's
     * retarget — concretely, for every NODE rule in the document this
     * change published whose target node lives on the changed machine,
     * the applied document either carries the same target_host or no
     * longer carries the rule at all (a later removal makes the
     * retarget question moot). An applied version NUMBER larger than
     * the published one proves nothing by itself: under consecutive
     * changes A->B the agent may have applied B's document only, and
     * the A change must not be announced complete off the back of B's
     * ACK. Such a follower is `superseded` once a newer change for the
     * same landing machine has published a higher version for it, and
     * the change closes as `superseded` — terminal, never `complete`.
     * Any failure keeps the change `partial`; all confirmed (or no
     * followers at all) completes it.
     *
     * Round-4 amendment (N02-2): the content check is fail-closed —
     * when the published or applied document needed as evidence is
     * missing, the follower is NOT confirmed (round 3 fell back to
     * the version comparison alone; that fallback is withdrawn) and
     * the block is audit-logged for investigation.
     */
    private function refreshChangeStatus(RelayAddressChange $change): void
    {
        $followers = $change->followers ?? [];
        foreach ($followers as $relayMachineId => $state) {
            if (($state['status'] ?? '') !== 'published' || !isset($state['version'])) {
                continue;
            }
            $relayMachine = ServerMachine::find((int) $relayMachineId);
            if (!$relayMachine || (int) $relayMachine->relay_applied_version < (int) $state['version']) {
                continue;
            }
            if ($this->appliedDocumentMatchesChange($change, $relayMachine, (int) $state['version'])) {
                $state['status'] = 'confirmed';
            } elseif ($this->followerSupersededByNewerChange($change, (int) $relayMachineId, (int) $state['version'])) {
                $state['status'] = 'superseded';
            } else {
                continue;
            }
            $state['updated_at'] = now()->toIso8601String();
            $followers[$relayMachineId] = $state;
        }
        $statuses = array_values(array_map(fn ($s) => $s['status'] ?? 'pending', $followers));
        $status = match (true) {
            in_array('failed', $statuses, true) => RelayAddressChange::STATUS_PARTIAL,
            $statuses === [] || array_filter($statuses, fn ($s) => $s !== 'confirmed') === [] => RelayAddressChange::STATUS_COMPLETE,
            array_filter($statuses, fn ($s) => !in_array($s, ['confirmed', 'superseded'], true)) === [] => RelayAddressChange::STATUS_SUPERSEDED,
            in_array('pending', $statuses, true) => RelayAddressChange::STATUS_IN_PROGRESS,
            default => RelayAddressChange::STATUS_AWAITING_ACK,
        };
        $change->forceFill([
            'followers' => $followers,
            'status' => $status,
            'completed_at' => $status === RelayAddressChange::STATUS_COMPLETE ? ($change->completed_at ?? now()) : null,
        ])->save();
    }

    /**
     * N02 idempotency probe: if the machine's latest published document
     * already equals the document that would be derived from current
     * state (same relay_enabled / machine_status / rules — version and
     * generated_at excluded, they differ on every build), return that
     * version. The publish this change owes has then already happened,
     * whoever ran it, and publishing again would only inflate the
     * config version.
     */
    private function publishedVersionCoveringCurrentDocument(ServerMachine $relayMachine): ?int
    {
        $latest = RelayConfigOutbox::where('machine_id', $relayMachine->id)
            ->orderByDesc('version')->first();
        if (!$latest) {
            return null;
        }
        $published = json_decode($latest->payload, true);
        if (!is_array($published)) {
            return null;
        }
        $shape = fn (array $doc): array => [
            'relay_enabled' => (bool) ($doc['relay_enabled'] ?? false),
            'machine_status' => $doc['machine_status'] ?? null,
            'rules' => $doc['rules'] ?? [],
        ];
        $current = $this->config->buildDocument($relayMachine);
        return $shape($current) === $shape($published) ? (int) $latest->version : null;
    }

    /**
     * The content half of the N02 confirmation standard: compare the
     * NODE targets this change published (the outbox at the recorded
     * version) with the targets in the document the agent has actually
     * applied (the outbox at relay_applied_version), restricted to
     * rules whose target node lives on the changed machine.
     *
     * N02-2 (GPT round 4, supersedes the round-3 fallback): when
     * either document is unavailable there is nothing to judge
     * against — and missing evidence must never read as a match.
     * Round 3 fell back to the caller's version comparison in that
     * case, which is fail-open: a change could be confirmed with no
     * content evidence at all. The check is now fail-closed: the
     * follower stays unconfirmed and the gap is written to the audit
     * log so the missing document can be investigated.
     */
    private function appliedDocumentMatchesChange(RelayAddressChange $change, ServerMachine $relayMachine, int $publishedVersion): bool
    {
        $expected = RelayConfigOutbox::where('machine_id', $relayMachine->id)
            ->where('version', $publishedVersion)->first();
        $applied = RelayConfigOutbox::where('machine_id', $relayMachine->id)
            ->where('version', (int) $relayMachine->relay_applied_version)->first();
        if (!$expected || !$applied) {
            $missing = !$expected
                ? 'published document v' . $publishedVersion . ' is missing'
                : 'applied document v' . (int) $relayMachine->relay_applied_version . ' is missing';
            RelayAuditLog::record($relayMachine->id, null, 'system', null, 'machine.public_address.confirm_blocked',
                'address change #' . $change->id . ' on machine ' . $change->machine_id
                . ': follower confirmation blocked — ' . $missing
                . '; no content evidence, follower stays unconfirmed (fail-closed)');
            return false;
        }
        $nodeIds = Server::where('machine_id', $change->machine_id)
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
        $targetsOf = function (string $payload) use ($nodeIds): array {
            $doc = json_decode($payload, true);
            $map = [];
            foreach (($doc['rules'] ?? []) as $entry) {
                if (($entry['target_type'] ?? null) === RelayRule::TARGET_NODE
                    && in_array((int) ($entry['target_node_id'] ?? 0), $nodeIds, true)) {
                    $map[(string) $entry['rule_code']] = (string) $entry['target_host'];
                }
            }
            return $map;
        };
        $expectedTargets = $targetsOf($expected->payload);
        $appliedTargets = $targetsOf($applied->payload);
        foreach ($expectedTargets as $code => $host) {
            if (array_key_exists($code, $appliedTargets) && $appliedTargets[$code] !== $host) {
                return false;
            }
        }
        return true;
    }

    /**
     * A follower whose applied document does not embody this change is
     * superseded when a NEWER address change for the same landing
     * machine has published a higher version for this same follower —
     * the newer change owns the follower's convergence from there.
     */
    private function followerSupersededByNewerChange(RelayAddressChange $change, int $relayMachineId, int $publishedVersion): bool
    {
        return RelayAddressChange::where('machine_id', $change->machine_id)
            ->where('id', '>', $change->id)
            ->get()
            ->contains(function (RelayAddressChange $newer) use ($relayMachineId, $publishedVersion) {
                $state = ($newer->followers ?? [])[$relayMachineId] ?? null;
                return $state !== null && isset($state['version']) && (int) $state['version'] > $publishedVersion;
            });
    }

    /**
     * B03 precheck (hardens R02): probe the effective landing ports of
     * exactly the nodes this change will retarget — the agent-deliverable
     * nodes on this machine that at least one follower targets — and
     * require the new address to accept a TCP connection on EVERY one
     * of them. Round 1 sampled at most five ports and passed the change
     * when any single one answered.
     *
     * Ports are derived from the nodes directly (strict direct_port
     * semantics), not via nodeLandingEndpoint(): that helper reads the
     * machine's CURRENT address, which is exactly what is being
     * changed — and may still be empty on a first-time assignment.
     *
     * The probe is a TCP connect only: it proves a listener accepts a
     * connection, NOT that a full proxy handshake succeeds. Protocol
     * proof comes from the publish + agent ACK + live path and is
     * reported separately — a passing precheck must never be cited as
     * handshake evidence.
     *
     * @return array{probed: int[], passed: int[], failed: int[]}
     */
    private function precheckReachable(ServerMachine $machine, string $address, array $followerIds, string $actorType, ?int $actorId): array
    {
        $targetNodeIds = RelayRule::where('target_type', RelayRule::TARGET_NODE)
            ->whereIn('machine_id', $followerIds)
            ->pluck('target_node_id')->filter()->unique()->all();
        $ports = Server::where('machine_id', $machine->id)
            ->whereIn('id', $targetNodeIds)
            ->get()
            ->filter(fn (Server $node) => ServerService::isAgentDeliverable($node))
            ->map(fn (Server $node) => (int) ($node->direct_port !== null ? $node->direct_port : $node->server_port))
            ->filter(fn (int $port) => $port >= 1 && $port <= 65535)
            ->unique()
            ->values()
            ->all();
        if (!$ports) {
            // No deliverable target is affected: nothing is retargeted,
            // so there is nothing to refuse over; the publish-side
            // BROKEN gate still applies to whatever does get published.
            return ['probed' => [], 'passed' => [], 'failed' => []];
        }
        $result = RelayTargetProbe::probeAllPorts($this->tcpProber, $address, $ports);
        $report = ['probed' => $ports, 'passed' => $result['passed'], 'failed' => $result['failed']];
        if ($result['failed']) {
            RelayAuditLog::record($machine->id, null, $actorType, $actorId, 'machine.public_address.precheck_failed',
                $address . ' ports probed: ' . implode(',', $ports) . '; TCP-failed: ' . implode(',', $result['failed']));
            throw new RelayValidationException(
                'public_address precheck failed: ' . $address . ' does not accept a TCP connection on '
                . count($result['failed']) . ' of ' . count($ports) . ' affected landing port(s) (failed: '
                . implode(', ', $result['failed']) . '; passed: ' . implode(', ', $result['passed'])
                . '); the change is refused and the current address stays in force'
            );
        }
        return $report;
    }

    public static function isValidPublicAddress(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP) !== false
            || (bool) preg_match('/^(?=.{1,253}$)([a-zA-Z0-9-]+\.)*[a-zA-Z0-9-]+$/', $address);
    }
}
