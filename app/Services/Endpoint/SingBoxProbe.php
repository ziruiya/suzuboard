<?php

namespace App\Services\Endpoint;

use App\Exceptions\SuzuEndpointException;
use App\Models\Server;
use App\Models\SuzuEndpointContract;

/**
 * Full-fidelity probe engine: runs a short-lived sing-box client built
 * from the frozen contract (plus live key material read at runtime from
 * the node row — never from the contract tables), then verifies through
 * the tunnel that (1) the check URL answers 204 and (2) the exit IP is
 * exactly the candidate origin IP. Wrong keys, wrong short ids, wrong
 * service names or wrong WS paths all fail the tunnel and therefore fail
 * this probe — an open TCP port can never fake a pass.
 */
class SingBoxProbe
{
    public function __construct(
        private string $binary,
        private int $timeout = 30,
    ) {
    }

    public function available(): bool
    {
        return $this->binary !== '' && is_file($this->binary) && is_executable($this->binary);
    }

    /**
     * Build the sing-box outbound for a contract pointing at $targetIp.
     * Key material (Reality public key) is read from the live node row at
     * runtime; the contract supplies every frozen expectation.
     */
    public function buildOutbound(SuzuEndpointContract $contract, Server $server, string $uuid, string $targetIp): array
    {
        $settings = $server->protocol_settings ?? [];
        $originPort = $contract->server_port ?? $contract->client_port;

        $outbound = [
            'tag' => 'proxy',
            'server' => $targetIp,
            'server_port' => $originPort,
        ];

        if ($contract->protocol === Server::TYPE_VLESS) {
            $outbound['type'] = 'vless';
            $outbound['uuid'] = $uuid;
            if ($contract->flow) {
                $outbound['flow'] = $contract->flow;
            }
            if ($contract->transport === 'ws') {
                $hostHeader = $settings['network_settings']['headers']['Host'] ?? $contract->legacy_hostname;
                $outbound['tls'] = [
                    'enabled' => true,
                    'server_name' => $contract->sni ?? $contract->legacy_hostname,
                ];
                $outbound['transport'] = [
                    'type' => 'ws',
                    'path' => $contract->ws_path,
                    'headers' => ['Host' => $hostHeader],
                ];
            } else {
                $reality = $settings['reality_settings'] ?? [];
                $publicKey = $reality['public_key'] ?? null;
                if (!$publicKey) {
                    throw new SuzuEndpointException('live node has no reality public key to probe with');
                }
                // Cross-check live key material against the frozen fingerprint.
                if ($contract->reality_public_key_fingerprint
                    && !hash_equals($contract->reality_public_key_fingerprint, hash('sha256', (string) $publicKey))) {
                    throw new SuzuEndpointException('live reality public key does not match contract fingerprint');
                }
                $outbound['tls'] = [
                    'enabled' => true,
                    'server_name' => $contract->sni,
                    'utls' => ['enabled' => true, 'fingerprint' => $settings['utls']['fingerprint'] ?? 'chrome'],
                    'reality' => [
                        'enabled' => true,
                        'public_key' => $publicKey,
                        'short_id' => $contract->short_id ?? '',
                    ],
                ];
            }
            return $outbound;
        }

        if ($contract->protocol === Server::TYPE_TROJAN) {
            $outbound['type'] = 'trojan';
            $outbound['password'] = $uuid;
            $outbound['tls'] = [
                'enabled' => true,
                'server_name' => $contract->sni ?? $contract->legacy_hostname,
                'alpn' => ['h2'],
            ];
            if ($contract->transport === 'grpc') {
                $outbound['transport'] = [
                    'type' => 'grpc',
                    'service_name' => $contract->grpc_service_name,
                ];
            }
            return $outbound;
        }

        throw new SuzuEndpointException("sing-box probe does not support protocol {$contract->protocol}");
    }

    /**
     * Run sing-box against the candidate origin and verify through-tunnel
     * behaviour.
     *
     * @return array<int, array{name: string, ok: bool, detail: string}>
     */
    public function run(SuzuEndpointContract $contract, Server $server, string $uuid, string $targetIp, string $checkUrl, string $echoUrl): array
    {
        if (!$this->available()) {
            throw new SuzuEndpointException('sing-box binary not available: ' . $this->binary);
        }
        $outbound = $this->buildOutbound($contract, $server, $uuid, $targetIp);
        $port = $this->freePort();

        $config = [
            'log' => ['level' => 'error'],
            'inbounds' => [[
                'type' => 'mixed',
                'tag' => 'in',
                'listen' => '127.0.0.1',
                'listen_port' => $port,
            ]],
            'outbounds' => [$outbound, ['type' => 'direct', 'tag' => 'direct']],
            'route' => ['final' => 'proxy'],
        ];

        $dir = storage_path('app/endpoint-probes');
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $file = $dir . '/probe-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($file, json_encode($config));
        chmod($file, 0600);

        $process = null;
        try {
            $process = proc_open(
                [$this->binary, 'run', '-c', $file],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes
            );
            if (!is_resource($process)) {
                throw new SuzuEndpointException('failed to start sing-box probe process');
            }
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            // Wait for the local mixed inbound to accept connections.
            $deadline = microtime(true) + min(10, $this->timeout);
            $ready = false;
            while (microtime(true) < $deadline) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $stderr = stream_get_contents($pipes[2]) ?: '';
                    throw new SuzuEndpointException('sing-box exited early: ' . trim(substr($stderr, 0, 300)));
                }
                $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.3);
                if ($conn) {
                    fclose($conn);
                    $ready = true;
                    break;
                }
                usleep(150000);
            }
            if (!$ready) {
                throw new SuzuEndpointException('sing-box probe inbound did not come up');
            }

            $checks = [];
            [$code, $body, $error] = $this->httpThroughProxy($port, $checkUrl);
            $checks[] = [
                'name' => 'singbox_tunnel_http',
                'ok' => $code === 204,
                'detail' => $code === 204
                    ? "through-tunnel GET {$checkUrl} -> 204"
                    : "through-tunnel GET {$checkUrl} -> " . ($error ?: "HTTP {$code}"),
            ];
            if ($code === 204) {
                [$echoCode, $echoBody, $echoError] = $this->httpThroughProxy($port, $echoUrl);
                $exitIp = trim((string) $echoBody);
                $checks[] = [
                    'name' => 'singbox_exit_ip',
                    'ok' => $echoCode === 200 && $exitIp === $targetIp,
                    'detail' => $echoCode === 200
                        ? "exit ip {$exitIp} " . ($exitIp === $targetIp ? 'matches' : "does NOT match candidate {$targetIp}")
                        : 'ip echo failed: ' . ($echoError ?: "HTTP {$echoCode}"),
                ];
            }
            return $checks;
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            @unlink($file); // contains the probe uuid — never leave it behind
        }
    }

    /** @return array{0: int, 1: ?string, 2: ?string} [httpCode, body, error] */
    private function httpThroughProxy(int $port, string $url): array
    {
        if (!function_exists('curl_init')) {
            return [0, null, 'ext-curl unavailable'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_PROXY => "http://127.0.0.1:{$port}",
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => max(5, intdiv($this->timeout, 2)),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'suzu-preflight',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);
        return [$code, $body === false ? null : (string) $body, $error];
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new SuzuEndpointException('cannot allocate probe port: ' . $errstr);
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
