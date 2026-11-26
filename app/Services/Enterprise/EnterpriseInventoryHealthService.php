<?php

namespace App\Services\Enterprise;

use App\Models\EnterpriseInventoryAlert;
use App\Models\EnterpriseInventoryKit;
use App\Models\EnterpriseInventoryLog;
use App\Models\EnterpriseInventoryNode;
use App\Models\EnterpriseInventoryRule;
use App\Models\EnterprisePurpose;
use App\Models\Server;
use App\Models\User;
use App\Services\ServerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Kit verification (SB-ENT-INV-01): content correctness from panel data,
 * reachability through the KitProbe edge. A kit enters READY only after
 * three consecutive full passes; a READY kit that fails three consecutive
 * checks is quarantined.
 *
 * Full-mode tunnel probes run under the probe identity, whose kit-group
 * membership the landing agents only pick up on their next user pull
 * (~60 s cycle). Probing immediately after the group change therefore
 * reports the sync lag as a tunnel failure (v1.3.7 acceptance: a probe
 * 75 s after the group change passed both nodes cleanly). The probe
 * section retries with bounded backoff while the probe user stays in
 * the kit group — the same doctrine as delivery verification (v1.3.5) —
 * and only the exhausted final attempt is recorded as a health result,
 * so a lagging first attempt never counts as a kit failure.
 */
class EnterpriseInventoryHealthService
{
    public const PROBE_EMAIL = 'enterprise-probe@suzu.internal';

    public function __construct(
        private KitProbe $probe,
        private EnterpriseInventoryService $inventory,
    ) {
    }

    /** The dedicated probe identity (V07: never the patrol account 429). */
    public function probeUser(): User
    {
        $user = User::where('email', self::PROBE_EMAIL)->first();
        if ($user) {
            return $user;
        }
        return User::create([
            'email' => self::PROBE_EMAIL,
            'password' => bcrypt(Str::random(48)),
            'uuid' => Str::uuid()->toString(),
            'token' => Str::random(32),
            'transfer_enable' => 1024 * 1073741824,
            'expired_at' => now()->addYears(5)->timestamp,
            'banned' => 0,
            'is_admin' => 0,
        ]);
    }

    /**
     * Verify a stock kit. $mode = 'full' (content + TCP + through-tunnel
     * 204/exit) or 'light' (content + TCP only).
     *
     * @return array{ok: bool, checks: array<int, array{name: string, ok: bool, detail: string}>}
     */
    public function verifyKit(EnterpriseInventoryKit $kit, string $mode = 'full'): array
    {
        $checks = [];
        $nodes = $kit->nodes()->with('server')->get();
        if ($nodes->count() !== 2) {
            $checks[] = ['name' => 'kit_nodes', 'ok' => false, 'detail' => 'kit must have exactly 2 nodes, has ' . $nodes->count()];
            return $this->recordResult($kit, $mode, $checks);
        }

        foreach ($nodes as $invNode) {
            $checks = array_merge($checks, $this->contentChecks($kit, $invNode));
        }

        $probeUser = $this->probeUser();
        // Retries only make sense in full mode: light checks (content +
        // TCP) do not depend on the probe identity's group membership.
        $backoff = $mode === 'full'
            ? array_map('intval', (array) config('suzu_enterprise.health_verify_backoff_seconds', [20, 40, 60, 90]))
            : [];
        $maxAttempts = count($backoff) + 1;
        // The probe-user lock must outlive the whole retry window,
        // otherwise a concurrent verifier could steal the identity
        // mid-verification and restore a stale group under us.
        $lock = Cache::lock('enterprise-probe-user', 120 + array_sum($backoff) + 120);
        try {
            $lock->block(30);
            $originalGroup = $probeUser->group_id;
            $probeUser->group_id = $kit->stock_group_id;
            $probeUser->save();
            try {
                $probeChecks = [];
                for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                    $probeChecks = $this->probeNodes($kit, $nodes, $probeUser, $mode);
                    if (collect($probeChecks)->every(fn($c) => $c['ok'])) {
                        break;
                    }
                    if ($attempt < $maxAttempts) {
                        $delay = (int) ($backoff[$attempt - 1] ?? 0);
                        EnterpriseInventoryLog::record($kit->id, 'health.verify.retry', "health verification attempt {$attempt}/{$maxAttempts} failed; retrying in {$delay}s (agent sync may lag)");
                        if ($delay > 0) {
                            sleep($delay);
                        }
                    }
                }
                $checks = array_merge($checks, $probeChecks);
            } finally {
                $probeUser->group_id = $originalGroup;
                $probeUser->save();
                $probeUser->refresh();
                if ((int) $probeUser->group_id !== (int) $originalGroup) {
                    $probeUser->group_id = $originalGroup;
                    $probeUser->save();
                    EnterpriseInventoryLog::record($kit->id, 'health.probe_group_restore_failed', 'probe user group restore did not read back after verification; re-applied');
                }
            }
        } catch (\Throwable $e) {
            $checks[] = ['name' => 'probe_lock', 'ok' => false, 'detail' => $e->getMessage()];
        } finally {
            $lock->release();
        }

        return $this->recordResult($kit, $mode, $checks);
    }

    /**
     * One probe attempt over both kit nodes under the probe identity
     * (which the caller has already grouped into the kit). TCP first,
     * then — in full mode — the through-tunnel 204 and exit-IP checks.
     *
     * @return array<int, array{name: string, ok: bool, detail: string}>
     */
    private function probeNodes(EnterpriseInventoryKit $kit, iterable $nodes, User $probeUser, string $mode): array
    {
        $checks = [];
        foreach ($nodes as $invNode) {
            $host = $invNode->dns_alias ?: ($invNode->server?->host ?? '');
            $tcpOk = $host && $this->probe->tcpReachable($host, (int) $invNode->port);
            $checks[] = [
                'name' => "tcp_{$invNode->node_role}",
                'ok' => (bool) $tcpOk,
                'detail' => $tcpOk ? "{$host}:{$invNode->port} reachable" : "{$host}:{$invNode->port} unreachable",
            ];
            if ($mode === 'full' && $invNode->server_id) {
                $result = $this->probe->probeThroughNode($kit, (int) $invNode->server_id, $probeUser);
                $checks[] = [
                    'name' => "tunnel204_{$invNode->node_role}",
                    'ok' => $result['ok'] && $result['status'] === 204,
                    'detail' => $result['ok'] ? 'generate_204 through tunnel' : ('probe failed: ' . ($result['error'] ?? 'unknown')),
                ];
                $expectedExit = $this->expectedExitIp($kit, $invNode->node_role);
                if ($result['ok'] && $expectedExit) {
                    $checks[] = [
                        'name' => "exit_ip_{$invNode->node_role}",
                        'ok' => $result['exit_ip'] === $expectedExit,
                        'detail' => "exit {$result['exit_ip']} " . ($result['exit_ip'] === $expectedExit ? 'matches' : "!= expected {$expectedExit}"),
                    ];
                }
            }
        }
        return $checks;
    }

    /**
     * Post-assignment delivery verification: render the CUSTOMER-facing
     * subscription through the probe identity (temporarily grouped into the
     * kit) and probe both nodes end to end.
     *
     * @return array{ok: bool, checks: array<int, array{name: string, ok: bool, detail: string}>}
     */
    public function verifyDelivery(EnterpriseInventoryKit $kit): array
    {
        $checks = [];
        $probeUser = $this->probeUser();
        $lock = Cache::lock('enterprise-probe-user', 120);
        try {
            $lock->block(30);
            $originalGroup = $probeUser->group_id;
            $probeUser->group_id = $kit->stock_group_id;
            $probeUser->save();
            try {
                $servers = ServerService::getAvailableServers($probeUser->fresh());
                $byId = collect($servers)->keyBy('id');
                foreach ($kit->nodes()->get() as $invNode) {
                    $row = $byId->get($invNode->server_id);
                    $checks[] = [
                        'name' => "subscription_{$invNode->node_role}",
                        'ok' => $row !== null
                            && (int) $row['port'] === (int) $invNode->port
                            && ($row['password'] ?? null) === $probeUser->uuid,
                        'detail' => $row ? "node {$invNode->server_id} rendered at port {$row['port']}" : "node {$invNode->server_id} missing from subscription",
                    ];
                    if ($invNode->server_id) {
                        $result = $this->probe->probeThroughNode($kit, (int) $invNode->server_id, $probeUser);
                        $checks[] = [
                            'name' => "delivery204_{$invNode->node_role}",
                            'ok' => $result['ok'] && $result['status'] === 204,
                            'detail' => $result['ok'] ? 'generate_204 through tunnel' : ('probe failed: ' . ($result['error'] ?? 'unknown')),
                        ];
                    }
                }
            } finally {
                $probeUser->group_id = $originalGroup;
                $probeUser->save();
            }
        } catch (\Throwable $e) {
            $checks[] = ['name' => 'probe_lock', 'ok' => false, 'detail' => $e->getMessage()];
        } finally {
            $lock->release();
        }
        $ok = collect($checks)->every(fn($c) => $c['ok']);
        EnterpriseInventoryLog::record($kit->id, $ok ? 'delivery.verify.pass' : 'delivery.verify.fail', json_encode($checks));
        return ['ok' => $ok, 'checks' => $checks];
    }

    /** @return array<int, array{name: string, ok: bool, detail: string}> */
    private function contentChecks(EnterpriseInventoryKit $kit, EnterpriseInventoryNode $invNode): array
    {
        $checks = [];
        $server = $invNode->server;
        $role = $invNode->node_role;
        if (!$server) {
            return [['name' => "node_{$role}", 'ok' => false, 'detail' => 'server row missing']];
        }
        // AUD-015 isolation: a kit node may belong to the kit group ONLY.
        $groupIds = array_map('strval', $server->group_ids ?? []);
        $checks[] = [
            'name' => "group_isolation_{$role}",
            'ok' => $groupIds === [(string) $kit->stock_group_id],
            'detail' => 'group_ids=' . json_encode($groupIds) . ' expected [' . $kit->stock_group_id . ']',
        ];
        $checks[] = [
            'name' => "entry_port_{$role}",
            'ok' => (int) $server->port === (int) $invNode->port && (int) $server->server_port === (int) $invNode->landing_port,
            'detail' => "client port {$server->port}, listen port {$server->server_port}",
        ];
        $checks[] = [
            'name' => "entry_host_{$role}",
            'ok' => $server->host === $invNode->dns_alias,
            'detail' => "host {$server->host}",
        ];
        $settings = $server->protocol_settings ?? [];
        $snapshot = \App\Models\EnterpriseKitContractSnapshot::where('kit_id', $kit->id)
            ->where('server_id', $server->id)
            ->orderByDesc('contract_version')
            ->first();
        if ($kit->purpose?->protocol === EnterprisePurpose::PROTOCOL_SW1) {
            $checks[] = [
                'name' => "sw1_path_{$role}",
                'ok' => ($settings['network_settings']['path'] ?? null) === '/SuzuWebSocket'
                    && ($snapshot?->ws_path === null || $snapshot->ws_path === '/SuzuWebSocket'),
                'detail' => 'ws path ' . ($settings['network_settings']['path'] ?? 'null'),
            ];
            $checks[] = [
                'name' => "sw1_sni_{$role}",
                'ok' => !empty($settings['tls_settings']['server_name'])
                    && ($snapshot?->sni === null || $snapshot->sni === $settings['tls_settings']['server_name']),
                'detail' => 'sni ' . ($settings['tls_settings']['server_name'] ?? 'null'),
            ];
        } else {
            $reality = $settings['reality_settings'] ?? [];
            $checks[] = [
                'name' => "reality_key_{$role}",
                'ok' => !empty($reality['public_key'])
                    && ($snapshot?->public_key === null || hash_equals((string) $snapshot->public_key, (string) $reality['public_key'])),
                'detail' => 'reality public key ' . (!empty($reality['public_key']) ? 'present' : 'MISSING'),
            ];
            $checks[] = [
                'name' => "reality_shortid_{$role}",
                'ok' => ($snapshot?->short_id === null || ($reality['short_id'] ?? null) === $snapshot->short_id)
                    && !empty($reality['server_name']),
                'detail' => 'short_id ' . ($reality['short_id'] ?? 'null') . ', sni ' . ($reality['server_name'] ?? 'null'),
            ];
        }
        return $checks;
    }

    private function expectedExitIp(EnterpriseInventoryKit $kit, string $role): ?string
    {
        $rule = EnterpriseInventoryRule::where('kit_id', $kit->id)
            ->where('node_role', $role)
            ->first();
        return $rule?->target_ip;
    }

    private function recordResult(EnterpriseInventoryKit $kit, string $mode, array $checks): array
    {
        $ok = collect($checks)->every(fn($c) => $c['ok']);
        $kit->last_probe_at = now();
        if ($ok) {
            // SB-ER-007: only a PASS stamps the freshness clock. A
            // failing probe updates last_probe_at (attempt) but must
            // never refresh assignment eligibility.
            $kit->last_probe_ok_at = now();
            $kit->health_fail_count = 0;
            if ($mode === 'full') {
                $kit->last_full_probe_at = now();
                $kit->verify_expires_at = now()->addHours(24);
            }
            $kit->save();
            EnterpriseInventoryLog::record($kit->id, "health.{$mode}.pass", json_encode($checks));
            if ($kit->status === EnterpriseInventoryKit::STATUS_VERIFYING && $mode === 'full'
                && $this->fullPassStreak($kit) >= 3) {
                $this->inventory->transition($kit, EnterpriseInventoryKit::STATUS_READY, 'kit.ready', 'three consecutive full verifications passed');
                $this->inventory->refreshLevel($kit->purpose_id);
            }
        } else {
            $kit->health_fail_count = (int) $kit->health_fail_count + 1;
            $kit->save();
            EnterpriseInventoryLog::record($kit->id, "health.{$mode}.fail", json_encode($checks));
            if ($kit->status === EnterpriseInventoryKit::STATUS_READY && $kit->health_fail_count >= 3) {
                $this->inventory->transition($kit, EnterpriseInventoryKit::STATUS_QUARANTINED, 'kit.quarantined', 'three consecutive health check failures');
                EnterpriseInventoryAlert::raise($kit->purpose_id, $kit->id, EnterpriseInventoryAlert::TYPE_HEALTH_QUARANTINED, "Kit #{$kit->id} quarantined after 3 consecutive health failures");
                $this->inventory->refreshLevel($kit->purpose_id);
            }
        }
        return ['ok' => $ok, 'checks' => $checks];
    }

    /** Consecutive full-pass streak, derived from the kit log. */
    private function fullPassStreak(EnterpriseInventoryKit $kit): int
    {
        $events = EnterpriseInventoryLog::where('kit_id', $kit->id)
            ->whereIn('event', ['health.full.pass', 'health.full.fail'])
            ->orderByDesc('id')
            ->limit(10)
            ->pluck('event');
        $streak = 0;
        foreach ($events as $event) {
            if ($event === 'health.full.pass') {
                $streak++;
            } else {
                break;
            }
        }
        return $streak;
    }
}
