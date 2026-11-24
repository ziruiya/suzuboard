<?php

namespace App\Services\Enterprise;

use App\Models\EnterpriseInventoryAlert;
use App\Models\EnterpriseInventoryKit;
use App\Models\EnterpriseInventoryLog;
use App\Models\EnterpriseInventoryNode;
use App\Models\EnterpriseInventoryRule;
use App\Models\EnterpriseKitContractSnapshot;
use App\Models\EnterprisePurpose;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\SuzuNodeProfile;
use Illuminate\Support\Facades\DB;

/**
 * Builds one stock kit for a purpose: stock group, (once entry rules are
 * available) the two landing nodes, the DNS alias and the contract
 * snapshot. Entry rules are the scarce resource: for API-less providers
 * (csmzzmb) ops pre-creates them and registers the stock; the builder
 * consumes one primary/backup pair per kit. Without a pair the kit waits
 * in BUILDING and an alert is raised (E13).
 */
class EnterpriseKitBuilderService
{
    /** Subscription sort for dedicated lines: ahead of the VIP block, primary before backup. */
    public const SORT_PRIMARY = 321;
    public const SORT_BACKUP = 322;

    public function __construct(
        private EnterpriseInventoryService $inventory,
        private EnterpriseDnsService $dns,
    ) {
    }

    public function buildKit(EnterprisePurpose $purpose, string $reason = 'replenish'): EnterpriseInventoryKit
    {
        if (!$purpose->blueprintComplete()) {
            EnterpriseInventoryAlert::raise($purpose->id, null, EnterpriseInventoryAlert::TYPE_BLUEPRINT_INCOMPLETE, "Purpose {$purpose->code} blueprint is incomplete (machine/protocol/provider/dns suffix); cannot build kits");
            throw new \RuntimeException("Blueprint incomplete for purpose {$purpose->code}");
        }

        $kit = DB::transaction(function () use ($purpose, $reason) {
            $sequence = $this->inventory->nextSequence($purpose->id);
            // ServerGroup is fully guarded in this codebase: build it the
            // same way GroupController does.
            $group = new ServerGroup();
            $group->name = sprintf('[STOCK] ENT-%s-#%03d', $purpose->code, $sequence);
            $group->save();
            $kit = EnterpriseInventoryKit::create([
                'purpose_id' => $purpose->id,
                'stock_group_id' => $group->id,
                'sequence' => $sequence,
                'status' => EnterpriseInventoryKit::STATUS_BUILDING,
                'build_reason' => $reason,
            ]);
            EnterpriseInventoryLog::record($kit->id, 'kit.building', "group {$group->id} created");
            return $kit;
        });

        $pair = $this->consumeRulePair($purpose, $kit);
        if (!$pair) {
            $this->alertRuleStock($purpose);
            return $kit->fresh();
        }
        $this->materializeNodes($purpose, $kit, $pair);
        return $kit->fresh();
    }

    /**
     * Admin confirm flow: attach two registered READY rules to a BUILDING
     * kit that has no nodes yet, then build its nodes and verify.
     */
    public function attachRules(EnterpriseInventoryKit $kit, array $ruleIds): EnterpriseInventoryKit
    {
        $purpose = $kit->purpose;
        $rules = EnterpriseInventoryRule::whereIn('id', $ruleIds)
            ->where('status', EnterpriseInventoryRule::STATUS_READY)
            ->lockForUpdate()
            ->get();
        if ($rules->count() !== 2) {
            throw new \RuntimeException('Exactly two READY rules are required');
        }
        $pair = $this->claimPair($kit, $rules->all());
        $this->materializeNodes($purpose, $kit, $pair);
        return $kit->fresh();
    }

    /**
     * Rebuild a quarantined kit: retire its old nodes/rules and build
     * fresh nodes from a new rule pair.
     */
    public function rebuildKit(EnterpriseInventoryKit $kit): EnterpriseInventoryKit
    {
        if ($kit->status !== EnterpriseInventoryKit::STATUS_QUARANTINED) {
            throw new \RuntimeException('Only QUARANTINED kits can be rebuilt');
        }
        DB::transaction(function () use ($kit) {
            foreach ($kit->nodes as $invNode) {
                if ($invNode->server_id) {
                    Server::whereKey($invNode->server_id)->update([
                        'enabled' => false,
                        'show' => false,
                        'group_ids' => json_encode([]),
                    ]);
                }
            }
            EnterpriseInventoryRule::where('kit_id', $kit->id)
                ->update(['status' => EnterpriseInventoryRule::STATUS_DISABLED]);
            $this->inventory->transition($kit, EnterpriseInventoryKit::STATUS_BUILDING, 'kit.rebuild', 'admin rebuild');
        });
        $purpose = $kit->purpose;
        $pair = $this->consumeRulePair($purpose, $kit);
        if (!$pair) {
            $this->alertRuleStock($purpose);
            return $kit->fresh();
        }
        $this->materializeNodes($purpose, $kit, $pair, rebuild: true);
        return $kit->fresh();
    }

    /**
     * Consume one READY primary/backup rule pair for the purpose.
     *
     * @return array<int, EnterpriseInventoryRule>|null [primary, backup]
     */
    private function consumeRulePair(EnterprisePurpose $purpose, EnterpriseInventoryKit $kit): ?array
    {
        return DB::transaction(function () use ($purpose, $kit) {
            $rules = EnterpriseInventoryRule::where('purpose_id', $purpose->id)
                ->where('provider', $purpose->entry_provider)
                ->where('status', EnterpriseInventoryRule::STATUS_READY)
                ->whereNull('kit_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->limit(2)
                ->get();
            if ($rules->count() < 2) {
                return null;
            }
            if ($rules[0]->target_ip !== $rules[1]->target_ip) {
                // A kit's primary and backup must land on the same machine.
                EnterpriseInventoryLog::record($kit->id, 'kit.rules_mismatch', 'rule pair targets differ: ' . $rules[0]->target_ip . ' vs ' . $rules[1]->target_ip);
                return null;
            }
            return $this->claimPair($kit, $rules->all());
        });
    }

    /** @param array<int, EnterpriseInventoryRule> $rules */
    private function claimPair(EnterpriseInventoryKit $kit, array $rules): array
    {
        $roles = [EnterpriseInventoryNode::ROLE_PRIMARY, EnterpriseInventoryNode::ROLE_BACKUP];
        foreach ($rules as $i => $rule) {
            $rule->kit_id = $kit->id;
            $rule->node_role = $roles[$i];
            $rule->status = EnterpriseInventoryRule::STATUS_IN_USE;
            $rule->save();
        }
        EnterpriseInventoryLog::record($kit->id, 'kit.rules_claimed', 'rules ' . implode(',', array_map(fn($r) => $r->id, $rules)));
        return $rules;
    }

    /** @param array<int, EnterpriseInventoryRule> $pair */
    private function materializeNodes(EnterprisePurpose $purpose, EnterpriseInventoryKit $kit, array $pair, bool $rebuild = false): void
    {
        $alias = sprintf(
            'ent-%s-%03d.%s',
            strtolower(str_replace('_', '-', $purpose->code)),
            $kit->sequence,
            $purpose->dns_alias_suffix
        );

        DB::transaction(function () use ($purpose, $kit, $pair, $alias, $rebuild) {
            $roles = [EnterpriseInventoryNode::ROLE_PRIMARY, EnterpriseInventoryNode::ROLE_BACKUP];
            foreach ($pair as $i => $rule) {
                $role = $roles[$i];
                $server = $this->createNodeServer($purpose, $kit, $rule, $role, $alias);
                if ($rebuild) {
                    $invNode = EnterpriseInventoryNode::where('kit_id', $kit->id)->where('node_role', $role)->first();
                    $invNode->server_id = $server->id;
                    $invNode->port = $rule->public_entry_port;
                    $invNode->landing_port = $rule->target_port;
                    $invNode->dns_alias = $alias;
                    $invNode->save();
                } else {
                    EnterpriseInventoryNode::create([
                        'kit_id' => $kit->id,
                        'server_id' => $server->id,
                        'node_role' => $role,
                        'port' => $rule->public_entry_port,
                        'landing_port' => $rule->target_port,
                        'dns_alias' => $alias,
                    ]);
                }
                $this->captureSnapshot($purpose, $kit, $server, $rule, $alias);
            }
            $kit->current_contract_version = 1;
            $kit->save();
        });

        if (!$this->dns->ensureAlias($alias, (string) $purpose->entry_host)) {
            EnterpriseInventoryAlert::raise($purpose->id, $kit->id, EnterpriseInventoryAlert::TYPE_DNS_ALIAS_PENDING, "DNS alias {$alias} -> {$purpose->entry_host} is pending manual creation");
            EnterpriseInventoryLog::record($kit->id, 'kit.dns_pending', $alias);
        }

        $this->inventory->transition($kit, EnterpriseInventoryKit::STATUS_VERIFYING, 'kit.verifying', 'nodes materialized');
        // READY requires three consecutive full passes (health service).
        $health = app(EnterpriseInventoryHealthService::class);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $result = $health->verifyKit($kit->fresh(), 'full');
            if (!$result['ok']) {
                break;
            }
            if ($kit->fresh()->status === EnterpriseInventoryKit::STATUS_READY) {
                break;
            }
        }
    }

    private function createNodeServer(EnterprisePurpose $purpose, EnterpriseInventoryKit $kit, EnterpriseInventoryRule $rule, string $role, string $alias): Server
    {
        $isPrimary = $role === EnterpriseInventoryNode::ROLE_PRIMARY;
        $name = sprintf('[STOCK] %s %s #%03d', $purpose->name, $isPrimary ? '主线' : '备线', $kit->sequence);
        $settings = $purpose->protocol === EnterprisePurpose::PROTOCOL_SW1
            ? $this->sw1Settings($alias)
            : $this->realitySettings();

        $server = Server::create([
            'type' => Server::TYPE_VLESS,
            'name' => $name,
            'host' => $alias,
            'port' => (string) $rule->public_entry_port,
            'server_port' => $rule->target_port,
            'group_ids' => [(string) $kit->stock_group_id],
            'machine_id' => $purpose->machine_id,
            'show' => false,
            'enabled' => true,
            'rate' => 1,
            'sort' => $isPrimary ? self::SORT_PRIMARY : self::SORT_BACKUP,
            'protocol_settings' => $settings,
            'cert_config' => $purpose->protocol === EnterprisePurpose::PROTOCOL_SW1
                ? ['cert_mode' => 'dns', 'domain_name' => $alias]
                : null,
        ]);

        if ($purpose->protocol === EnterprisePurpose::PROTOCOL_SW1) {
            SuzuNodeProfile::create([
                'server_id' => $server->id,
                'profile_code' => 'sw1',
                'schema_version' => 1,
                'relay_policy' => 'required',
                'rollout_stage' => 'production',
                'client_policy' => 'standard',
                'udp_enabled' => false,
            ]);
        }
        return $server;
    }

    private function realitySettings(): array
    {
        $keypair = sodium_crypto_box_keypair();
        $encode = fn(string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        return [
            'tls' => 2,
            'flow' => 'xtls-rprx-vision',
            'network' => 'tcp',
            'network_settings' => null,
            'tls_settings' => ['server_name' => null, 'allow_insecure' => false],
            'reality_settings' => [
                'server_name' => 'www.icloud.com',
                'server_port' => '443',
                'public_key' => $encode(sodium_crypto_box_publickey($keypair)),
                'private_key' => $encode(sodium_crypto_box_secretkey($keypair)),
                'short_id' => bin2hex(random_bytes(8)),
                'allow_insecure' => false,
            ],
            'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
            'multiplex' => ['enabled' => false],
            'encryption' => null,
        ];
    }

    private function sw1Settings(string $alias): array
    {
        return [
            'tls' => 1,
            'flow' => null,
            'network' => 'ws',
            'network_settings' => [
                'path' => '/SuzuWebSocket',
                'headers' => ['Host' => $alias],
            ],
            'tls_settings' => ['server_name' => $alias, 'allow_insecure' => false],
            'reality_settings' => null,
            'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
            'multiplex' => ['enabled' => false],
            'encryption' => null,
        ];
    }

    private function captureSnapshot(EnterprisePurpose $purpose, EnterpriseInventoryKit $kit, Server $server, EnterpriseInventoryRule $rule, string $alias): void
    {
        $settings = $server->protocol_settings ?? [];
        $isSw1 = $purpose->protocol === EnterprisePurpose::PROTOCOL_SW1;
        EnterpriseKitContractSnapshot::create([
            'kit_id' => $kit->id,
            'server_id' => $server->id,
            'contract_version' => 1,
            'entry_address' => $alias,
            'entry_port' => $rule->public_entry_port,
            'protocol' => $purpose->protocol,
            'uuid' => '', // per-user credential by panel design; bound at assignment (contract v2)
            'short_id' => $isSw1 ? null : ($settings['reality_settings']['short_id'] ?? null),
            'sni' => $isSw1 ? ($settings['tls_settings']['server_name'] ?? null) : ($settings['reality_settings']['server_name'] ?? null),
            'public_key' => $isSw1 ? null : ($settings['reality_settings']['public_key'] ?? null),
            'ws_path' => $isSw1 ? '/SuzuWebSocket' : null,
            'service_name' => null,
            'target_ip' => $rule->target_ip,
            'target_port' => $rule->target_port,
            'dns_alias' => $alias,
            'revision' => 1,
            'captured_at' => now(),
        ]);
    }

    private function alertRuleStock(EnterprisePurpose $purpose): void
    {
        $remaining = EnterpriseInventoryRule::where('purpose_id', $purpose->id)
            ->where('provider', $purpose->entry_provider)
            ->where('status', EnterpriseInventoryRule::STATUS_READY)
            ->whereNull('kit_id')
            ->count();
        if ($remaining === 0) {
            EnterpriseInventoryAlert::raise($purpose->id, null, EnterpriseInventoryAlert::TYPE_RULE_STOCK_EMPTY, "Purpose {$purpose->code} has no READY entry rules left; instant delivery paused for this SKU");
        } else {
            EnterpriseInventoryAlert::raise($purpose->id, null, EnterpriseInventoryAlert::TYPE_RULE_STOCK_LOW, "Purpose {$purpose->code} has only {$remaining} READY entry rule(s) left; a kit needs a pair");
        }
        EnterpriseInventoryLog::record(null, 'kit.rules_unavailable', "purpose {$purpose->code} remaining READY rules: {$remaining}");
    }
}
