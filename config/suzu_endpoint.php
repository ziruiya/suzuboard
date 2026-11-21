<?php

/*
|--------------------------------------------------------------------------
| Suzu Endpoint Recovery (SB-Endpoint-01)
|--------------------------------------------------------------------------
|
| Server-side configuration for the endpoint contract registry, origin
| preflight probes and DNS cutover. Secrets (the Cloudflare API token)
| come from the server environment only: never store them in the
| database, never expose them to the admin frontend.
|
*/

return [
    'cloudflare' => [
        // Token with Zone.DNS Edit on the suzu.club / zexqoravi.com zones.
        // Set SUZU_CF_API_TOKEN in the panel .env. Falls back to the
        // generic CLOUDFLARE_API_TOKEN if present.
        'api_token' => env('SUZU_CF_API_TOKEN', env('CLOUDFLARE_API_TOKEN')),
        'base_url' => env('SUZU_CF_API_BASE', 'https://api.cloudflare.com/client/v4'),
        'timeout' => (int) env('SUZU_CF_API_TIMEOUT', 15),
    ],

    'probe' => [
        // Panel user whose uuid is borrowed at runtime as the probe
        // credential (patrol account). The uuid itself is never written
        // to the contract tables.
        'user_id' => (int) env('SUZU_ENDPOINT_PROBE_USER_ID', 429),
        // sing-box binary used as the full-fidelity probe engine. When it
        // is missing, PHP-native probes run instead and Reality contracts
        // can only reach "degraded" (auth cannot be verified natively).
        'singbox_binary' => env('SUZU_ENDPOINT_SINGBOX_BINARY', '/usr/local/bin/sing-box'),
        'connect_timeout' => (int) env('SUZU_ENDPOINT_CONNECT_TIMEOUT', 5),
        'io_timeout' => (int) env('SUZU_ENDPOINT_IO_TIMEOUT', 8),
        'singbox_timeout' => (int) env('SUZU_ENDPOINT_SINGBOX_TIMEOUT', 30),
        // Reachability + exit-IP checks performed through the probed node.
        'http_check_url' => env('SUZU_ENDPOINT_HTTP_CHECK_URL', 'http://www.gstatic.com/generate_204'),
        'ip_echo_url' => env('SUZU_ENDPOINT_IP_ECHO_URL', 'https://api.ipify.org'),
    ],
];
