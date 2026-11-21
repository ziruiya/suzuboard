<?php

namespace App\Services;

use App\Models\Server;
use App\Models\SuzuEndpointBinding;
use App\Models\SuzuEndpointChange;
use App\Models\SuzuEndpointContract;
use App\Models\User;
use App\Services\Endpoint\PhpNativeProbes;
use App\Services\Endpoint\SingBoxProbe;
use Illuminate\Support\Facades\DB;

/**
 * Origin preflight: before a DNS cutover points a frozen client hostname
 * at a new origin machine, prove the candidate origin actually serves the
 * contract — same origin port, same protocol semantics, same exit IP.
 *
 * Verdicts:
 *  - passed:   every applicable check passed, including a real protocol
 *              handshake (sing-box engine, or PHP-native for B/C groups).
 *  - degraded: endpoint is alive and consistent, but one check could not
 *              be fully verified (Reality auth with no sing-box binary).
 *              A cutover must NOT proceed on a degraded preflight.
 *  - failed:   at least one check failed (wrong port, wrong key, wrong
 *              service name, contract drift, wrong exit IP...).
 *  - error:    the preflight itself could not run (no probe user, ...).
 *
 * Every run is persisted to the binding (health_status + last_preflight)
 * and to the audit trail.
 */
class SuzuOriginPreflightService
{
    public function __construct(
        private SuzuEndpointContractService $contracts,
    ) {
    }

    /**
     * @return array{contract_id: int, target_ip: string, origin_port: ?int, engine: string,
     *               overall: string, checks: array, started_at: string, finished_at: string}
     */
    public function run(SuzuEndpointContract $contract, string $targetIp, ?int $actorId = null): array
    {
        $startedAt = now();
        $checks = [];
        $overall = null;
        $engine = 'none';
        $originPort = $contract->server_port ?? $contract->client_port;

        $binding = SuzuEndpointBinding::firstOrCreate(
            ['contract_id' => $contract->id],
            ['entry_mode' => SuzuEndpointBinding::ENTRY_DNS, 'health_status' => SuzuEndpointBinding::HEALTH_UNKNOWN, 'revision' => 1]
        );

        // 1. The live node must still match the frozen contract; probing a
        //    drifted contract would validate the wrong thing.
        $integrity = $this->contracts->verifyIntegrity($contract);
        $checks[] = [
            'name' => 'contract_integrity',
            'ok' => $integrity['ok'],
            'detail' => $integrity['ok']
                ? 'live node matches frozen contract hash'
                : 'live node drifted from contract: ' . implode(', ', array_keys($integrity['drift'])),
        ];
        if (!$integrity['ok']) {
            $overall = SuzuEndpointBinding::HEALTH_FAILED;
        }

        $server = Server::find($contract->logical_node_id);
        if ($overall === null && !$server) {
            $checks[] = ['name' => 'node_exists', 'ok' => false, 'detail' => 'logical node no longer exists'];
            $overall = SuzuEndpointBinding::HEALTH_ERROR;
        }

        // 2. Probe credential: the patrol user's uuid, borrowed at runtime.
        $probeUuid = null;
        if ($overall === null) {
            $probeUserId = (int) config('suzu_endpoint.probe.user_id', 429);
            $probeUser = User::find($probeUserId);
            $probeUuid = $probeUser?->uuid;
            if (!$probeUuid && $this->needsCredential($contract)) {
                $checks[] = ['name' => 'probe_credential', 'ok' => false, 'detail' => "probe user {$probeUserId} missing or has no uuid"];
                $overall = SuzuEndpointBinding::HEALTH_ERROR;
            }
        }

        // 3. Protocol checks.
        if ($overall === null) {
            try {
                $singBox = new SingBoxProbe(
                    (string) config('suzu_endpoint.probe.singbox_binary', '/usr/local/bin/sing-box'),
                    (int) config('suzu_endpoint.probe.singbox_timeout', 30),
                );
                if ($singBox->available()) {
                    $engine = 'sing-box';
                    $probeChecks = $singBox->run(
                        $contract,
                        $server,
                        (string) $probeUuid,
                        $targetIp,
                        (string) config('suzu_endpoint.probe.http_check_url'),
                        (string) config('suzu_endpoint.probe.ip_echo_url'),
                    );
                    $checks = array_merge($checks, $probeChecks);
                } else {
                    $engine = 'php-native';
                    $checks = array_merge($checks, $this->phpNativeChecks($contract, $targetIp, $originPort, (string) $probeUuid));
                }
            } catch (\Throwable $e) {
                $checks[] = ['name' => 'probe_engine', 'ok' => false, 'detail' => $e->getMessage()];
                $overall = SuzuEndpointBinding::HEALTH_ERROR;
            }
        }

        if ($overall === null) {
            $hasFailure = collect($checks)->contains(fn ($c) => empty($c['ok']) && empty($c['degraded']));
            $hasDegraded = collect($checks)->contains(fn ($c) => !empty($c['degraded']));
            $overall = $hasFailure
                ? SuzuEndpointBinding::HEALTH_FAILED
                : ($hasDegraded ? SuzuEndpointBinding::HEALTH_DEGRADED : SuzuEndpointBinding::HEALTH_PASSED);
        }

        $result = [
            'contract_id' => $contract->id,
            'target_ip' => $targetIp,
            'origin_port' => $originPort,
            'engine' => $engine,
            'overall' => $overall,
            'checks' => $checks,
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
        ];

        DB::transaction(function () use ($binding, $contract, $overall, $result, $actorId) {
            $binding->health_status = $overall;
            $binding->last_preflight = $result;
            $binding->save();

            SuzuEndpointChange::create([
                'contract_id' => $contract->id,
                'action' => SuzuEndpointChange::ACTION_PREFLIGHT,
                'actor_id' => $actorId,
                'from_revision' => $binding->revision,
                'to_revision' => $binding->revision,
                'postcheck_result' => $result,
                'rollback_status' => SuzuEndpointChange::ROLLBACK_NONE,
            ]);
        });

        return $result;
    }

    private function needsCredential(SuzuEndpointContract $contract): bool
    {
        return in_array($contract->auth_namespace, ['user-uuid', 'user-pass'], true);
    }

    /**
     * PHP-native fallback checks (no sing-box binary on the host).
     *
     * @return array<int, array>
     */
    private function phpNativeChecks(SuzuEndpointContract $contract, string $targetIp, int $originPort, string $probeUuid): array
    {
        $probes = new PhpNativeProbes(
            (int) config('suzu_endpoint.probe.connect_timeout', 5),
            (int) config('suzu_endpoint.probe.io_timeout', 8),
        );
        $checkUrl = (string) config('suzu_endpoint.probe.http_check_url', 'http://www.gstatic.com/generate_204');
        $checkHost = parse_url($checkUrl, PHP_URL_HOST) ?: 'www.gstatic.com';
        $checkPath = parse_url($checkUrl, PHP_URL_PATH) ?: '/generate_204';

        $tcp = $probes->tcpConnect($targetIp, $originPort);
        $checks = [$tcp];
        if (!$tcp['ok']) {
            return $checks;
        }

        // C group: VLESS + WS + TLS — full native semantic verification.
        if ($contract->protocol === Server::TYPE_VLESS && $contract->transport === 'ws') {
            $tls = $probes->tlsConnect($targetIp, $originPort, $contract->sni ?? $contract->legacy_hostname);
            if (!$tls['ok']) {
                $checks[] = ['name' => 'tls_handshake', 'ok' => false, 'detail' => $tls['error']];
                return $checks;
            }
            $checks[] = ['name' => 'tls_handshake', 'ok' => true, 'detail' => 'tls established with contract sni'];
            $settings = Server::find($contract->logical_node_id)?->protocol_settings ?? [];
            $hostHeader = $settings['network_settings']['headers']['Host'] ?? $contract->legacy_hostname;
            $checks[] = $probes->wsVlessCheck($tls['stream'], $hostHeader, (string) $contract->ws_path, $probeUuid, $checkHost, $checkPath);
            fclose($tls['stream']);
            return $checks;
        }

        // B group: Trojan + gRPC + TLS — full native semantic verification via curl HTTP/2.
        if ($contract->protocol === Server::TYPE_TROJAN && $contract->transport === 'grpc') {
            $checks[] = $probes->grpcGunCheck(
                $targetIp,
                $originPort,
                $contract->sni ?? $contract->legacy_hostname,
                (string) $contract->grpc_service_name,
                $probeUuid,
                $checkHost,
                $checkPath
            );
            return $checks;
        }

        // A / VIP: VLESS + Reality — native PHP cannot do the Reality auth
        // handshake. Verify the unauthenticated fallback behaviour (the
        // camouflage site's certificate is served for the Reality SNI)
        // and report degraded, never passed.
        if ($contract->protocol === Server::TYPE_VLESS && $contract->reality_public_key_fingerprint) {
            $tls = $probes->tlsConnect($targetIp, $originPort, $contract->sni, true);
            if (!$tls['ok']) {
                $checks[] = ['name' => 'tls_handshake', 'ok' => false, 'detail' => $tls['error']];
                return $checks;
            }
            $san = $tls['cert_san'] ?? [];
            fclose($tls['stream']);
            $sni = (string) $contract->sni;
            $matches = collect($san)->contains(function ($name) use ($sni) {
                $name = ltrim((string) $name, '*.');
                return $sni === $name || str_ends_with($sni, '.' . $name);
            });
            $checks[] = [
                'name' => 'reality_dest_certificate',
                'ok' => $matches,
                'degraded' => $matches,
                'detail' => $matches
                    ? "endpoint serves camouflage certificate for {$sni} (san: " . implode(',', $san) . '); reality auth NOT verified without sing-box engine'
                    : "certificate SAN (" . implode(',', $san) . ") does not cover reality sni {$sni}",
            ];
            return $checks;
        }

        // Anything else: alive, but not semantically verified natively.
        $checks[] = [
            'name' => 'protocol_semantic',
            'ok' => true,
            'degraded' => true,
            'detail' => "no native semantic probe for {$contract->protocol}/{$contract->transport}; install sing-box for full verification",
        ];
        return $checks;
    }
}
