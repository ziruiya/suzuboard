<?php

namespace App\Services\Enterprise;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Default DNS driver. When SUZU_CF_API_TOKEN is configured it upserts the
 * alias as a DNS-only CNAME in Cloudflare (TTL 120, matching the endpoint
 * contract doctrine). Without a token it records the intent and reports
 * "pending" so the caller can raise an ops alert instead of pretending the
 * name resolves.
 */
class CloudflareEnterpriseDnsService implements EnterpriseDnsService
{
    public function ensureAlias(string $alias, string $targetHost): bool
    {
        $token = (string) (env('SUZU_CF_API_TOKEN') ?: '');
        $zoneId = (string) (env('SUZU_CF_ZONE_ID') ?: '');
        if ($token === '' || $zoneId === '') {
            Log::info('enterprise dns alias pending (no CF token configured)', [
                'alias' => $alias,
                'target' => $targetHost,
            ]);
            return false;
        }
        try {
            $client = Http::withToken($token)->timeout(15);
            $existing = $client->get("https://api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records", [
                'name' => $alias,
            ]);
            $records = $existing->json('result') ?? [];
            $payload = [
                'type' => 'CNAME',
                'name' => $alias,
                'content' => $targetHost,
                'ttl' => 120,
                'proxied' => false,
            ];
            if ($records) {
                $id = $records[0]['id'];
                $resp = $client->put("https://api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records/{$id}", $payload);
            } else {
                $resp = $client->post("https://api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records", $payload);
            }
            return (bool) $resp->json('success');
        } catch (\Throwable $e) {
            Log::warning('enterprise dns alias upsert failed', ['alias' => $alias, 'error' => $e->getMessage()]);
            return false;
        }
    }
}
