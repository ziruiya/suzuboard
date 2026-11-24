<?php

namespace App\Http\Middleware;

use App\Models\AdminAuditLog;
use Closure;

class RequestLog
{
    /**
     * AUD-024: the previous filter only removed top-level keys, so nested
     * payloads (node cert_config with private keys, reality key pairs,
     * auth_data, per-user subscribe URLs, …) were written to the audit
     * log in plaintext. Redaction is now recursive: any key whose name
     * contains one of these fragments has its whole value masked, at any
     * depth. Non-sensitive fields (ids, hosts, ports, paths, flags) are
     * kept verbatim so the log stays useful for troubleshooting.
     */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password',
        'passwd',
        'pwd',
        'token',
        'secret',
        'key',
        'cert',
        'private',
        'uuid',
        'credential',
        'auth',
        'subscribe_url',
    ];

    private const REDACTED = '***';

    public function handle($request, Closure $next)
    {
        if ($request->method() !== 'POST') {
            return $next($request);
        }

        $response = $next($request);

        try {
            $admin = $request->user();
            if (!$admin || !$admin->is_admin) {
                return $response;
            }

            $action = $this->resolveAction($request->path());
            $data = $this->sanitize($request->all());

            AdminAuditLog::insert([
                'admin_id' => $admin->id,
                'action' => $action,
                'method' => $request->method(),
                'uri' => $this->sanitizeUri($request->getRequestUri()),
                'request_data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'ip' => $request->getClientIp(),
                'created_at' => time(),
                'updated_at' => time(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('Audit log write failed: ' . $e->getMessage());
        }

        return $response;
    }

    private function sanitize(mixed $value, ?string $key = null): mixed
    {
        // Container values are walked so that non-secret siblings (a
        // cert_config's domain, an auth block's type) stay visible for
        // troubleshooting; leaf values under a sensitive key are masked.
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $out[$k] = $this->sanitize($v, is_string($k) ? $k : null);
            }
            return $out;
        }
        if ($key !== null && $this->isSensitiveKey($key)) {
            return self::REDACTED;
        }
        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);
        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }
        return false;
    }

    private function sanitizeUri(string $uri): string
    {
        $qpos = strpos($uri, '?');
        if ($qpos === false) {
            return $uri;
        }
        $path = substr($uri, 0, $qpos);
        $query = [];
        parse_str(substr($uri, $qpos + 1), $query);
        $query = $this->sanitize($query);
        return $path . '?' . http_build_query($query);
    }

    private function resolveAction(string $path): string
    {
        // api/v2/{secure_path}/user/update → user.update
        $path = preg_replace('#^api/v[12]/[^/]+/#', '', $path);
        // gift-card/create-template → gift_card.create_template
        $path = str_replace('-', '_', $path);
        // user/update → user.update, server/manage/sort → server_manage.sort
        $segments = explode('/', $path);
        $method = array_pop($segments);
        $resource = implode('_', $segments);

        return $resource . '.' . $method;
    }
}
