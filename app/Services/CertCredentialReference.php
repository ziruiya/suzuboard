<?php

namespace App\Services;

use App\Models\Server;

/**
 * The fleet's shared DNS-01 credential set for node certificates.
 *
 * There is deliberately no setting or env var holding these
 * credentials: production nodes carry the set per-node in their
 * cert_config (dns_provider / dns_env / email), and new TLS nodes
 * clone the set used by the majority of existing nodes — the same
 * set ops cloned by hand when repairing the first TRADE kits.
 * Extracted (SB-ER-010) from EnterpriseKitBuilderService so the
 * dedicated-machine provisioner writes the identical shape.
 */
class CertCredentialReference
{
    /**
     * The majority credential set among existing nodes with a complete
     * cert_config, or null when no node carries one.
     *
     * @return array{dns_provider: string, dns_env: array, email: string}|null
     */
    public static function majority(): ?array
    {
        $groups = [];
        foreach (Server::whereNotNull('cert_config')->get() as $server) {
            $cert = $server->cert_config;
            if (!is_array($cert) || empty($cert['dns_env']) || !is_array($cert['dns_env'])
                || empty($cert['dns_provider']) || empty($cert['email'])) {
                continue;
            }
            $key = md5(json_encode($cert['dns_env']));
            $groups[$key] ??= ['count' => 0, 'server_id' => 0, 'cert' => null];
            $groups[$key]['count']++;
            if ((int) $server->id > $groups[$key]['server_id']) {
                $groups[$key]['server_id'] = (int) $server->id;
                $groups[$key]['cert'] = $cert;
            }
        }
        if (!$groups) {
            return null;
        }
        uasort($groups, fn($a, $b) => ($b['count'] <=> $a['count']) ?: ($b['server_id'] <=> $a['server_id']));
        $cert = reset($groups)['cert'];
        return [
            'dns_provider' => $cert['dns_provider'],
            'dns_env' => $cert['dns_env'],
            'email' => $cert['email'],
        ];
    }

    /**
     * A production-shaped DNS-01 cert_config for $domain: the cloned
     * credential set when the fleet has one; otherwise the configured
     * provider/email with dns_env absent (it cannot be invented — ops
     * seeds the first credentialed node, exactly as for kit SW1).
     */
    public static function dnsCertConfig(string $domain): array
    {
        $config = ['cert_mode' => 'dns', 'domain' => $domain];
        $reference = self::majority();
        if ($reference !== null) {
            $config['dns_provider'] = $reference['dns_provider'];
            $config['dns_env'] = $reference['dns_env'];
            $config['email'] = $reference['email'];
            return $config;
        }
        $config['dns_provider'] = 'cloudflare';
        $email = admin_setting('machine_cert_email');
        if (!empty($email)) {
            $config['email'] = $email;
        }
        return $config;
    }

    /** True when a cert_config carries a complete DNS-01 credential set. */
    public static function isComplete(?array $cert): bool
    {
        return is_array($cert)
            && !empty($cert['dns_provider'])
            && !empty($cert['dns_env']) && is_array($cert['dns_env'])
            && !empty($cert['email']);
    }
}
