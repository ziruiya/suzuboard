<?php

namespace App\Services\Endpoint;

use RuntimeException;

/**
 * PHP-native origin probes.
 *
 * Design rule (F01b): an open TCP port is NEVER a pass. Every protocol
 * check below completes the real client-side handshake semantics:
 *  - C group (VLESS + WS + TLS): TLS with the contract SNI, a WebSocket
 *    upgrade on the frozen WS path, then an actual VLESS request; the
 *    origin must answer with the target site's HTTP response. A wrong
 *    path or a wrong uuid gets the connection dropped and fails.
 *  - B group (Trojan + gRPC + TLS): HTTP/2 (ALPN h2) to the frozen gRPC
 *    service, carrying a real Trojan first packet for the check host.
 *    A wrong service name or a wrong password yields no relayed HTTP
 *    response and fails.
 *  - A/VIP (VLESS + Reality): native PHP cannot perform the Reality
 *    X25519 auth handshake, so this probe only verifies that the
 *    endpoint answers TLS for the Reality camouflage SNI with the
 *    camouflage site's own certificate (the unauthenticated Reality
 *    fallback behaviour). The caller must treat that as "degraded",
 *    never "passed" — full verification requires the sing-box engine.
 */
class PhpNativeProbes
{
    public function __construct(
        private int $connectTimeout = 5,
        private int $ioTimeout = 8,
    ) {
    }

    /** @return array{name: string, ok: bool, detail: string, latency_ms?: int} */
    public function tcpConnect(string $ip, int $port): array
    {
        $start = microtime(true);
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client("tcp://{$ip}:{$port}", $errno, $errstr, $this->connectTimeout, STREAM_CLIENT_CONNECT);
        $latency = (int) round((microtime(true) - $start) * 1000);
        if ($stream === false) {
            return ['name' => 'tcp_connect', 'ok' => false, 'detail' => "connect failed: {$errstr} ({$errno})", 'latency_ms' => $latency];
        }
        fclose($stream);
        return ['name' => 'tcp_connect', 'ok' => true, 'detail' => "tcp {$ip}:{$port} reachable", 'latency_ms' => $latency];
    }

    /**
     * Open a TLS connection to ip:port presenting $sni.
     *
     * @return array{ok: bool, stream?: resource, cert_san?: array, error?: string}
     */
    public function tlsConnect(string $ip, int $port, ?string $sni, bool $captureCert = false): array
    {
        $ssl = [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ];
        if ($sni) {
            $ssl['SNI_enabled'] = true;
            $ssl['peer_name'] = $sni;
        }
        if ($captureCert) {
            $ssl['capture_peer_cert'] = true;
        }
        $context = stream_context_create(['ssl' => $ssl]);
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client("tls://{$ip}:{$port}", $errno, $errstr, $this->connectTimeout, STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            return ['ok' => false, 'error' => "tls connect failed: {$errstr} ({$errno})"];
        }
        stream_set_timeout($stream, $this->ioTimeout);

        $san = [];
        if ($captureCert) {
            $options = stream_context_get_options($stream);
            $cert = $options['ssl']['peer_certificate'] ?? null;
            if ($cert) {
                $parsed = openssl_x509_parse($cert);
                $alt = $parsed['extensions']['subjectAltName'] ?? '';
                foreach (explode(',', $alt) as $entry) {
                    $entry = trim($entry);
                    if (str_starts_with($entry, 'DNS:')) {
                        $san[] = substr($entry, 4);
                    }
                }
                if (!empty($parsed['subject']['CN'])) {
                    $san[] = $parsed['subject']['CN'];
                }
            }
        }
        return ['ok' => true, 'stream' => $stream, 'cert_san' => $san];
    }

    /**
     * C-group semantic check on an established TLS stream:
     * WebSocket upgrade on $path, then a VLESS request for $checkHost.
     *
     * @return array{name: string, ok: bool, detail: string}
     */
    public function wsVlessCheck($stream, string $hostHeader, string $path, string $uuid, string $checkHost, string $checkPath = '/generate_204', int $checkPort = 80): array
    {
        $name = 'ws_vless_semantic';
        try {
            $key = base64_encode(random_bytes(16));
            $request = "GET {$path} HTTP/1.1\r\n"
                . "Host: {$hostHeader}\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Key: {$key}\r\n"
                . "Sec-WebSocket-Version: 13\r\n\r\n";
            $this->writeAll($stream, $request);

            [$head, $leftover] = $this->readHttpHead($stream);
            if (!preg_match('#^HTTP/1\.1 101#', $head)) {
                $statusLine = strtok($head, "\r\n") ?: 'no response';
                return ['name' => $name, 'ok' => false, 'detail' => "websocket upgrade rejected: {$statusLine}"];
            }

            // VLESS request header + HTTP payload, as one WS binary frame.
            $uuidBytes = hex2bin(str_replace('-', '', $uuid));
            if ($uuidBytes === false || strlen($uuidBytes) !== 16) {
                return ['name' => $name, 'ok' => false, 'detail' => 'probe uuid malformed'];
            }
            $payload = "\x00" . $uuidBytes . "\x00" . "\x01"
                . pack('n', $checkPort)
                . "\x02" . chr(strlen($checkHost)) . $checkHost
                . "GET {$checkPath} HTTP/1.1\r\nHost: {$checkHost}\r\nUser-Agent: suzu-preflight\r\nConnection: close\r\n\r\n";
            $this->writeAll($stream, $this->wsFrame($payload));

            $buffer = $leftover;
            $deadline = microtime(true) + $this->ioTimeout;
            while (microtime(true) < $deadline) {
                if (preg_match('/HTTP\/1\.[01] (\d{3})/', $buffer, $m)) {
                    $code = (int) $m[1];
                    return ['name' => $name, 'ok' => $code >= 200 && $code < 400, 'detail' => "vless relay answered HTTP {$code} for {$checkHost}{$checkPath}"];
                }
                $frame = $this->readWsFrame($stream, $buffer);
                if ($frame === null) {
                    break; // EOF / close frame / timeout
                }
                if ($frame['opcode'] === 0x9) { // ping -> pong
                    $this->writeAll($stream, $this->wsFrame($frame['payload'], 0xA));
                    continue;
                }
                if ($frame['opcode'] === 0x8) {
                    break;
                }
                $buffer .= $frame['payload'];
            }
            return ['name' => $name, 'ok' => false, 'detail' => 'no HTTP response relayed through vless stream (wrong path/uuid or origin not vless)'];
        } catch (RuntimeException $e) {
            return ['name' => $name, 'ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /**
     * B-group semantic check: Trojan over gRPC (Gun) via curl HTTP/2.
     *
     * @return array{name: string, ok: bool, detail: string}
     */
    public function grpcGunCheck(string $ip, int $port, string $tlsHost, string $serviceName, string $trojanPassword, string $checkHost, string $checkPath = '/generate_204'): array
    {
        $name = 'grpc_trojan_semantic';
        if (!function_exists('curl_init')) {
            return ['name' => $name, 'ok' => false, 'detail' => 'ext-curl unavailable for http/2 probe'];
        }
        $version = curl_version();
        if (!(($version['features'] ?? 0) & CURL_VERSION_HTTP2)) {
            return ['name' => $name, 'ok' => false, 'detail' => 'curl built without http/2 support'];
        }

        // Trojan first packet: sha224(password) hex + CRLF + cmd + addr + port + payload.
        $trojan = hash('sha224', $trojanPassword) . "\r\n"
            . "\x01" . "\x03" . chr(strlen($checkHost)) . $checkHost . pack('n', 80)
            . "GET {$checkPath} HTTP/1.1\r\nHost: {$checkHost}\r\nUser-Agent: suzu-preflight\r\nConnection: close\r\n\r\n";
        $grpcBody = "\x00" . pack('N', strlen($trojan)) . $trojan;

        $url = "https://{$tlsHost}:{$port}/{$serviceName}/Conn";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $grpcBody,
            CURLOPT_HTTPHEADER => ['Content-Type: application/grpc', 'TE: trailers', 'User-Agent: grpc-php/suzu-preflight'],
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,
            CURLOPT_RESOLVE => ["{$tlsHost}:{$port}:{$ip}"],
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->ioTimeout + $this->connectTimeout,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $errno !== 0) {
            return ['name' => $name, 'ok' => false, 'detail' => "grpc request failed: {$error} (curl {$errno})"];
        }
        if (preg_match('/HTTP\/1\.[01] (\d{3})/', (string) $body, $m)) {
            $code = (int) $m[1];
            return ['name' => $name, 'ok' => $code >= 200 && $code < 400, 'detail' => "trojan/grpc relay answered HTTP {$code} for {$checkHost}{$checkPath} (edge status {$status})"];
        }
        return ['name' => $name, 'ok' => false, 'detail' => "no relayed HTTP response in grpc stream (edge status {$status}); wrong service name or password, or origin is not trojan-grpc"];
    }

    /** Read an HTTP response head; returns [head, leftoverBytes]. */
    private function readHttpHead($stream): array
    {
        $buffer = '';
        $deadline = microtime(true) + $this->ioTimeout;
        while (!str_contains($buffer, "\r\n\r\n")) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('timeout waiting for response head');
            }
            $chunk = fread($stream, 4096);
            if ($chunk === false || ($chunk === '' && feof($stream))) {
                throw new RuntimeException('connection closed before response head');
            }
            $buffer .= $chunk;
        }
        [$head, $leftover] = explode("\r\n\r\n", $buffer, 2);
        return [$head, $leftover ?? ''];
    }

    /** Encode one masked client WebSocket frame. */
    private function wsFrame(string $payload, int $opcode = 0x2): string
    {
        $length = strlen($payload);
        $head = chr(0x80 | $opcode);
        if ($length < 126) {
            $head .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $head .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $head .= chr(0x80 | 127) . pack('J', $length);
        }
        $mask = random_bytes(4);
        $masked = '';
        for ($i = 0; $i < $length; $i++) {
            $masked .= $payload[$i] ^ $mask[$i % 4];
        }
        return $head . $mask . $masked;
    }

    /**
     * Read one WebSocket frame. $buffer holds already-read bytes and is
     * updated with any surplus. Returns null on EOF/close/timeout.
     *
     * @return array{opcode: int, payload: string}|null
     */
    private function readWsFrame($stream, string &$buffer): ?array
    {
        $need = function (int $n) use ($stream, &$buffer): bool {
            $deadline = microtime(true) + $this->ioTimeout;
            while (strlen($buffer) < $n) {
                if (microtime(true) > $deadline) {
                    return false;
                }
                $chunk = fread($stream, 8192);
                if ($chunk === false || ($chunk === '' && feof($stream))) {
                    return false;
                }
                if ($chunk === '') {
                    $meta = stream_get_meta_data($stream);
                    if (!empty($meta['timed_out'])) {
                        return false;
                    }
                    continue;
                }
                $buffer .= $chunk;
            }
            return true;
        };

        if (!$need(2)) {
            return null;
        }
        $b0 = ord($buffer[0]);
        $b1 = ord($buffer[1]);
        $opcode = $b0 & 0x0F;
        $masked = (bool) ($b1 & 0x80);
        $length = $b1 & 0x7F;
        $offset = 2;
        if ($length === 126) {
            if (!$need(4)) return null;
            $length = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if (!$need(10)) return null;
            $length = unpack('J', substr($buffer, 2, 8))[1];
            $offset = 10;
        }
        $maskKey = '';
        if ($masked) {
            if (!$need($offset + 4)) return null;
            $maskKey = substr($buffer, $offset, 4);
            $offset += 4;
        }
        if (!$need($offset + $length)) {
            return null;
        }
        $payload = substr($buffer, $offset, $length);
        $buffer = substr($buffer, $offset + $length);
        if ($masked && $maskKey !== '') {
            $unmasked = '';
            for ($i = 0; $i < $length; $i++) {
                $unmasked .= $payload[$i] ^ $maskKey[$i % 4];
            }
            $payload = $unmasked;
        }
        return ['opcode' => $opcode, 'payload' => $payload];
    }

    private function writeAll($stream, string $bytes): void
    {
        $written = 0;
        $total = strlen($bytes);
        while ($written < $total) {
            $n = fwrite($stream, substr($bytes, $written));
            if ($n === false || $n === 0) {
                throw new RuntimeException('failed writing to origin stream');
            }
            $written += $n;
        }
    }
}
