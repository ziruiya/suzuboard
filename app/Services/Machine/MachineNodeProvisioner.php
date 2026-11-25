<?php

namespace App\Services\Machine;

use App\Models\MachineFulfillment;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\ServerMachine;
use App\Models\SuzuNodeProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Business plans batch 3: materialises a bought VPS on the panel side —
 * pre-registers the ServerMachine (admin pastes the pinned install
 * command on the VPS), creates the customer's dedicated group and exactly
 * three dedicated lines on that machine: Reality (VLESS), SS1
 * (Trojan+gRPC) and SW1 (VLESS+WS), three different ports, one IP.
 *
 * The machine name follows the owner's format: 「{邮箱前缀}用户VPS {ip}」,
 * falling back to U{user_id} when the email prefix is unusable.
 */
class MachineNodeProvisioner
{
    /** Pinned agent installer (ziruiya/xboard-node release v1.13). */
    public const INSTALLER_URL = 'https://raw.githubusercontent.com/ziruiya/xboard-node/v1.13/install.sh';

    public const PORT_REALITY = 443;
    public const PORT_SS1 = 8443;
    public const PORT_SW1 = 8444;

    public const SORT_REALITY = 341;
    public const SORT_SS1 = 342;
    public const SORT_SW1 = 343;

    public static function machineNameFor(User $user, string $ip): string
    {
        $prefix = '';
        if ($user->email && str_contains($user->email, '@')) {
            $prefix = preg_replace('/[^A-Za-z0-9._-]/', '', explode('@', $user->email)[0]) ?? '';
        }
        if ($prefix === '') {
            $prefix = 'U' . $user->id;
        }
        return $prefix . '用户VPS ' . $ip;
    }

    /** The pinned-version, machine-mode install command for the admin page. */
    public static function installCommand(ServerMachine $machine, ?string $panelUrl = null): string
    {
        $panelUrl = rtrim((string) ($panelUrl ?: admin_setting('app_url')), '/');
        return sprintf(
            'curl -fsSL %s | sudo bash -s -- --mode machine --panel %s --token %s --machine-id %d',
            self::INSTALLER_URL,
            escapeshellarg($panelUrl),
            escapeshellarg($machine->token),
            $machine->id
        );
    }

    /**
     * Create machine + dedicated group + the three nodes for a fulfilment
     * whose public IP has been backfilled. Idempotent per fulfilment:
     * a second call returns the already-provisioned artefacts.
     *
     * @return array{machine: ServerMachine, group: ServerGroup, servers: array<string, Server>}
     */
    public function provision(MachineFulfillment $fulfillment): array
    {
        if ($fulfillment->machine_id) {
            $machine = ServerMachine::findOrFail($fulfillment->machine_id);
            $group = ServerGroup::findOrFail($fulfillment->group_id);
            return [
                'machine' => $machine,
                'group' => $group,
                'servers' => [
                    'reality' => Server::find($fulfillment->reality_server_id),
                    'ss1' => Server::find($fulfillment->ss1_server_id),
                    'sw1' => Server::find($fulfillment->sw1_server_id),
                ],
            ];
        }

        $user = User::findOrFail($fulfillment->user_id);
        $ip = (string) $fulfillment->public_ip;
        $machineName = self::machineNameFor($user, $ip);

        return DB::transaction(function () use ($fulfillment, $user, $ip, $machineName) {
            $machine = ServerMachine::create([
                'name' => $machineName,
                'token' => ServerMachine::generateToken(),
                'notes' => '独享整机履约 #' . $fulfillment->id . '（订单 ' . $fulfillment->order_id . '）',
                'is_active' => true,
            ]);

            // ServerGroup is fully guarded in this codebase: build it the
            // same way GroupController does.
            $group = new ServerGroup();
            $group->name = $machineName . ' 专属组';
            $group->save();

            $servers = [
                'reality' => $this->createRealityNode($machine, $group, $ip, $machineName),
                'ss1' => $this->createSs1Node($machine, $group, $ip, $machineName),
                'sw1' => $this->createSw1Node($machine, $group, $ip, $machineName),
            ];

            $fulfillment->machine_id = $machine->id;
            $fulfillment->group_id = $group->id;
            $fulfillment->reality_server_id = $servers['reality']->id;
            $fulfillment->ss1_server_id = $servers['ss1']->id;
            $fulfillment->sw1_server_id = $servers['sw1']->id;
            $fulfillment->provisioned_at = now();
            $fulfillment->save();

            return ['machine' => $machine, 'group' => $group, 'servers' => $servers];
        });
    }

    private function baseNode(ServerMachine $machine, ServerGroup $group, string $ip, string $name, int $port, int $sort): array
    {
        return [
            'name' => $name,
            'host' => $ip,
            'port' => (string) $port,
            'server_port' => $port,
            'group_ids' => [(string) $group->id],
            'machine_id' => $machine->id,
            'show' => true,
            'enabled' => true,
            'rate' => 1,
            'sort' => $sort,
        ];
    }

    private function createRealityNode(ServerMachine $machine, ServerGroup $group, string $ip, string $machineName): Server
    {
        $keypair = sodium_crypto_box_keypair();
        $encode = fn(string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $server = Server::create(array_merge(
            $this->baseNode($machine, $group, $ip, $machineName . ' · Reality', self::PORT_REALITY, self::SORT_REALITY),
            [
                'type' => Server::TYPE_VLESS,
                'protocol_settings' => [
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
                ],
                'cert_config' => null,
            ]
        ));
        return $server;
    }

    private function createSs1Node(ServerMachine $machine, ServerGroup $group, string $ip, string $machineName): Server
    {
        $server = Server::create(array_merge(
            $this->baseNode($machine, $group, $ip, $machineName . ' · SS1', self::PORT_SS1, self::SORT_SS1),
            [
                'type' => Server::TYPE_TROJAN,
                'protocol_settings' => [
                    'tls' => 1,
                    'flow' => null,
                    'network' => 'grpc',
                    'network_settings' => ['serviceName' => 'SuzuStream'],
                    'tls_settings' => ['server_name' => $ip, 'allow_insecure' => false],
                    'reality_settings' => null,
                    'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
                    'multiplex' => ['enabled' => false],
                    'encryption' => null,
                ],
                // Production-shaped cert config (domain / dns_provider /
                // dns_env / email) via the shared credential clone
                // (SB-ER-010): previously this literal carried no
                // dns_env at all, so the agent could never complete a
                // DNS-01 issuance and the first machines would have
                // needed hand repair. The domain is the machine's
                // public IP until ops binds a name to the box;
                // delivery verification now refuses to deliver while
                // the credential set is incomplete.
                'cert_config' => \App\Services\CertCredentialReference::dnsCertConfig($ip),
            ]
        ));
        SuzuNodeProfile::create([
            'server_id' => $server->id,
            'profile_code' => 'ss1',
            'schema_version' => 1,
            'relay_policy' => 'required',
            'rollout_stage' => 'production',
            'client_policy' => 'standard',
            'udp_enabled' => false,
        ]);
        return $server;
    }

    private function createSw1Node(ServerMachine $machine, ServerGroup $group, string $ip, string $machineName): Server
    {
        $server = Server::create(array_merge(
            $this->baseNode($machine, $group, $ip, $machineName . ' · SW1', self::PORT_SW1, self::SORT_SW1),
            [
                'type' => Server::TYPE_VLESS,
                'protocol_settings' => [
                    'tls' => 1,
                    'flow' => null,
                    'network' => 'ws',
                    'network_settings' => [
                        'path' => '/SuzuWebSocket',
                        'headers' => ['Host' => $ip],
                    ],
                    'tls_settings' => ['server_name' => $ip, 'allow_insecure' => false],
                    'reality_settings' => null,
                    'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
                    'multiplex' => ['enabled' => false],
                    'encryption' => null,
                ],
                // See createSs1Node (SB-ER-010): shared credential clone.
                'cert_config' => \App\Services\CertCredentialReference::dnsCertConfig($ip),
            ]
        ));
        SuzuNodeProfile::create([
            'server_id' => $server->id,
            'profile_code' => 'sw1',
            'schema_version' => 1,
            'relay_policy' => 'required',
            'rollout_stage' => 'production',
            'client_policy' => 'standard',
            'udp_enabled' => false,
        ]);
        return $server;
    }
}
