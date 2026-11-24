<?php

namespace App\Services\Enterprise;

use App\Models\EnterpriseInventoryKit;
use App\Models\EnterpriseInventoryNode;
use App\Models\Server;
use App\Models\User;

/**
 * Production KitProbe: builds a short-lived sing-box client for one kit
 * node (same engine as the SB-Endpoint-01 preflight probes) and checks the
 * tunnel end to end — generate_204 plus the observed exit IP. When the
 * sing-box binary is unavailable the through-probe reports failure with an
 * explicit error instead of faking a pass.
 */
class SingBoxKitProbe implements KitProbe
{
    public function tcpReachable(string $host, int $port): bool
    {
        $conn = @fsockopen($host, $port, $errno, $errstr, 5);
        if ($conn) {
            fclose($conn);
            return true;
        }
        return false;
    }

    public function probeThroughNode(EnterpriseInventoryKit $kit, int $serverId, User $probeUser): array
    {
        $binary = (string) config('suzu_endpoint.probe.singbox_binary', '/usr/local/bin/sing-box');
        if (!is_file($binary) || !is_executable($binary)) {
            return ['ok' => false, 'exit_ip' => null, 'status' => null, 'error' => 'sing-box binary not available'];
        }
        $server = Server::find($serverId);
        $invNode = EnterpriseInventoryNode::where('kit_id', $kit->id)->where('server_id', $serverId)->first();
        if (!$server || !$invNode) {
            return ['ok' => false, 'exit_ip' => null, 'status' => null, 'error' => 'kit node not found'];
        }
        try {
            $outbound = $this->buildOutbound($server, $invNode, $probeUser);
        } catch (\Throwable $e) {
            return ['ok' => false, 'exit_ip' => null, 'status' => null, 'error' => $e->getMessage()];
        }

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
        $dir = storage_path('app/enterprise-probes');
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $file = $dir . '/probe-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($file, json_encode($config));
        chmod($file, 0600);

        $process = null;
        try {
            $process = proc_open(
                [$binary, 'run', '-c', $file],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes
            );
            if (!is_resource($process)) {
                return ['ok' => false, 'exit_ip' => null, 'status' => null, 'error' => 'failed to start sing-box'];
            }
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $deadline = microtime(true) + 10;
            $ready = false;
            while (microtime(true) < $deadline) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $stderr = trim(substr(stream_get_contents($pipes[2]) ?: '', 0, 300));
                    return ['ok' => false, 'exit_ip' => null, 'status' => null, 'error' => 'sing-box exited early: ' . $stderr];
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
                return ['ok' => false, 'exit_ip' => null, 'status' => null, 'error' => 'sing-box inbound did not come up'];
            }
            $checkUrl = (string) config('suzu_endpoint.probe.check_url', 'http://www.gstatic.com/generate_204');
            $echoUrl = (string) config('suzu_endpoint.probe.echo_url', 'https://api.ipify.org');
            [$code, , $error] = $this->httpThroughProxy($port, $checkUrl);
            if ($code !== 204) {
                return ['ok' => false, 'exit_ip' => null, 'status' => $code ?: null, 'error' => $error ?: "generate_204 returned HTTP {$code}"];
            }
            [$echoCode, $echoBody] = $this->httpThroughProxy($port, $echoUrl);
            $exitIp = $echoCode === 200 ? trim((string) $echoBody) : null;
            return ['ok' => true, 'exit_ip' => $exitIp, 'status' => 204, 'error' => null];
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            @unlink($file); // contains the probe uuid — never leave it behind
        }
    }

    private function buildOutbound(Server $server, EnterpriseInventoryNode $invNode, User $probeUser): array
    {
        $settings = $server->protocol_settings ?? [];
        $outbound = [
            'type' => 'vless',
            'tag' => 'proxy',
            'server' => $invNode->dns_alias ?: $server->host,
            'server_port' => (int) $invNode->port,
            'uuid' => $probeUser->uuid,
        ];
        if (($settings['network'] ?? null) === 'ws') {
            $outbound['tls'] = [
                'enabled' => true,
                'server_name' => $settings['tls_settings']['server_name'] ?? $invNode->dns_alias,
            ];
            $outbound['transport'] = [
                'type' => 'ws',
                'path' => $settings['network_settings']['path'] ?? '/SuzuWebSocket',
                'headers' => ['Host' => $settings['network_settings']['headers']['Host'] ?? $invNode->dns_alias],
            ];
            return $outbound;
        }
        $reality = $settings['reality_settings'] ?? [];
        if (empty($reality['public_key'])) {
            throw new \RuntimeException('kit node has no reality public key to probe with');
        }
        if (!empty($settings['flow'])) {
            $outbound['flow'] = $settings['flow'];
        }
        $outbound['tls'] = [
            'enabled' => true,
            'server_name' => $reality['server_name'] ?? null,
            'utls' => ['enabled' => true, 'fingerprint' => $settings['utls']['fingerprint'] ?? 'chrome'],
            'reality' => [
                'enabled' => true,
                'public_key' => $reality['public_key'],
                'short_id' => $reality['short_id'] ?? '',
            ],
        ];
        return $outbound;
    }

    /** @return array{0: int, 1: ?string, 2: ?string} */
    private function httpThroughProxy(int $port, string $url): array
    {
        if (!function_exists('curl_init')) {
            return [0, null, 'ext-curl unavailable'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_PROXY => "http://127.0.0.1:{$port}",
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => 'suzu-enterprise-probe',
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
            throw new \RuntimeException('cannot allocate probe port: ' . $errstr);
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
