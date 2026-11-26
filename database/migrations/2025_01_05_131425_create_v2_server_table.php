<?php

use App\Utils\Helper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('v2_server', function (Blueprint $table) {
            $table->id('id');
            $table->string('type')->comment('Server Type');
            $table->string('code')->nullable()->comment('Server Spectific Key');
            $table->unsignedInteger('parent_id')->nullable()->comment('Parent Server ID');
            $table->json('group_ids')->nullable()->comment('Group ID');
            $table->json('route_ids')->nullable()->comment('Route ID');
            $table->string('name')->comment('Server Name');
            $table->decimal('rate', 8, 2)->comment('Traffic Rate');
            $table->json('tags')->nullable()->comment('Server Tags');
            $table->string('host')->comment('Server Host');
            $table->string('port')->comment('Client Port');
            $table->integer('server_port')->comment('Server Port');
            $table->json('protocol_settings')->nullable();
            $table->boolean('show')->default(false)->comment('Show in List');
            $table->integer('sort')->nullable()->unsigned()->index();
            $table->timestamps();
            $table->unique(['type', 'code']);
        });

        // Migrate Trojan servers
        $trojanServers = DB::table('v2_server_trojan')->get();
        foreach ($trojanServers as $server) {
            DB::table('v2_server')->insert([
                'type' => 'trojan',
                'code' => (string) $server->id,
                'parent_id' => $server->parent_id,
                'group_ids' => $server->group_id ?: "[]",
                'route_ids' => $server->route_id ?: "[]",
                'name' => $server->name,
                'rate' => $server->rate,
                'tags' => $server->tags ?: "[]",
                'host' => $server->host,
                'port' => $server->port,
                'server_port' => $server->server_port,
                'protocol_settings' => json_encode([
                    'allow_insecure' => $server->allow_insecure,
                    'server_name' => $server->server_name,
                    'tls_settings' => [
                        'allow_insecure' => (bool) $server->allow_insecure,
                        'server_name' => $server->server_name,
                    ],
                    'network' => $server->network,
                    'network_settings' => json_decode($server->networkSettings ?? 'null', true)
                ]),
                'show' => $server->show,
                'sort' => $server->sort,
                'created_at' => date('Y-m-d H:i:s', $server->created_at),
                'updated_at' => date('Y-m-d H:i:s', $server->updated_at)
            ]);
        }

        // Migrate VMess servers
        $vmessServers = DB::table('v2_server_vmess')->get();
        foreach ($vmessServers as $server) {
            DB::table('v2_server')->insert([
                'type' => 'vmess',
                'code' => (string) $server->id,
                'parent_id' => $server->parent_id,
                'group_ids' => $server->group_id ?: "[]",
                'route_ids' => $server->route_id ?: "[]",
                'name' => $server->name,
                'rate' => $server->rate,
                'tags' => $server->tags ?: "[]",
                'host' => $server->host,
                'port' => $server->port,
                'server_port' => $server->server_port,
                'protocol_settings' => json_encode([
                    'tls' => $server->tls,
                    'network' => $server->network,
                    'rules' => json_decode($server->rules),
                    'network_settings' => json_decode($server->networkSettings),
                    'tls_settings' => json_decode($server->tlsSettings),
                ]),
                'show' => $server->show,
                'sort' => $server->sort,
                'created_at' => date('Y-m-d H:i:s', $server->created_at),
                'updated_at' => date('Y-m-d H:i:s', $server->updated_at)
            ]);
        }

        // Migrate VLESS servers
        $vlessServers = DB::table('v2_server_vless')->get();
        foreach ($vlessServers as $server) {
            $tlsSettings = optional(json_decode($server->tls_settings));
            DB::table('v2_server')->insert([
                'type' => 'vless',
                'code' => (string) $server->id,
                'parent_id' => $server->parent_id,
                'group_ids' => $server->group_id ?: "[]",
                'route_ids' => $server->route_id ?: "[]",
                'name' => $server->name,
                'rate' => $server->rate,
                'tags' => $server->tags ?: "[]",
                'host' => $server->host,
                'port' => $server->port,
                'server_port' => $server->server_port,
                'protocol_settings' => json_encode([
                    'tls' => $server->tls,
                    'tls_settings' => $tlsSettings,
                    'flow' => $server->flow,
                    'network' => $server->network,
                    'network_settings' => json_decode($server->network_settings),
                    'reality_settings' => ((int) $server->tls === 2 && $tlsSettings->public_key && $tlsSettings->server_name) ? [
                        'public_key' => $tlsSettings->public_key,
                        'short_id' => $tlsSettings->short_id,
                        'server_name' => $tlsSettings->server_name,
                        'server_port' => $tlsSettings->server_port,
                        'private_key' => $tlsSettings->private_key,
                    ] : null
                ]),
                'show' => $server->show,
                'sort' => $server->sort,
                'created_at' => date('Y-m-d H:i:s', $server->created_at),
                'updated_at' => date('Y-m-d H:i:s', $server->updated_at)
            ]);
        }

        // Migrate Shadowsocks servers
        $ssServers = DB::table('v2_server_shadowsocks')->get();
        foreach ($ssServers as $server) {
            DB::table('v2_server')->insert([
                'type' => 'shadowsocks',
                'code' => (string) $server->id,
                'parent_id' => $server->parent_id,
                'group_ids' => $server->group_id ?: "[]",
                'route_ids' => $server->route_id ?: "[]",
                'name' => $server->name,
                'rate' => $server->rate,
                'tags' => $server->tags ?: "[]",
                'host' => $server->host,
                'port' => $server->port,
                'server_port' => $server->server_port,
                'protocol_settings' => json_encode([
                    'cipher' => $server->cipher,
                    'obfs' => $server->obfs,
                    'obfs_settings' => json_decode($server->obfs_settings)
                ]),
                'show' => (bool) $server->show,
                'sort' => $server->sort,
                'created_at' => date('Y-m-d H:i:s', $server->created_at),
                'updated_at' => date('Y-m-d H:i:s', $server->updated_at)
            ]);
        }

        // Migrate Hysteria servers
        $hysteriaServers = DB::table(table: 'v2_server_hysteria')->get();
        foreach ($hysteriaServers as $server) {
            DB::table('v2_server')->insert([
                'type' => 'hysteria',
                'code' => (string) $server->id,
                'parent_id' => $server->parent_id,
                'group_ids' => $server->group_id ?: "[]",
                'route_ids' => $server->route_id ?: "[]",
                'name' => $server->name,
                'rate' => $server->rate,
                'tags' => $server->tags ?: "[]",
                'host' => $server->host,
                'port' => $server->port,
                'server_port' => $server->server_port,
                'protocol_settings' => json_encode([
                    'version' => $server->version,
                    'bandwidth' => [
                        'up' => $server->up_mbps,
                        'down' => $server->down_mbps,
                    ],
                    'obfs' => [
                        'open' => $server->is_obfs,
                        'type' => 'salamander',
                        'password' => Helper::getServerKey($server->created_at, 16),
                    ],
                    'tls' => [
                        'server_name' => $server->server_name,
                        'allow_insecure' => $server->insecure
                    ]
                ]),
                'show' => $server->show,
                'sort' => $server->sort,
                'created_at' => date('Y-m-d H:i:s', $server->created_at),
                'updated_at' => date('Y-m-d H:i:s', $server->updated_at)
            ]);
        }

        // Update parent_id for all servers
        $this->updateParentIds();

        // Drop old tables
        Schema::dropIfExists('v2_server_trojan');
        Schema::dropIfExists('v2_server_vmess');
        Schema::dropIfExists('v2_server_vless');
        Schema::dropIfExists('v2_server_shadowsocks');
        Schema::dropIfExists('v2_server_hysteria');
    }

    /**
     * Update parent_id references for all servers
     */
    private function updateParentIds(): void
    {
        // Get all servers that have a parent_id
        $servers = DB::table('v2_server')
            ->whereNotNull('parent_id')
            ->get();

        // Update each server's parent_id to reference the new table's id
        foreach ($servers as $server) {
            $parentId = DB::table('v2_server')
                ->where('type', $server->type)
                ->where('code', $server->parent_id)
                ->value('id');

            if ($parentId) {
                DB::table('v2_server')
                    ->where('id', $server->id)
                    ->update(['parent_id' => $parentId]);
            }
        }
    }

    public function down(): void
    {
        $rows = $this->legacyRows();
        foreach (array_keys($rows) as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException("Cannot roll back unified servers: $table already exists.");
            }
        }

        $created = [];
        try {
            $this->createLegacyTables($created);
            DB::transaction(function () use ($rows) {
                foreach ($rows as $table => $servers) {
                    foreach ($servers as $row) {
                        DB::table($table)->insert($row);
                    }
                }
            });
            Schema::drop('v2_server');
        } catch (\Throwable $error) {
            foreach (array_reverse($created) as $table) {
                Schema::dropIfExists($table);
            }
            throw $error;
        }
    }

    private function createLegacyTables(array &$created): void
    {
        // Recreate old tables
        Schema::create('v2_server_trojan', function (Blueprint $table) {
            $table->integer('id', true)->comment('节点ID');
            $table->string('group_id')->comment('节点组');
            $table->string('route_id')->nullable();
            $table->string('ips')->nullable();
            $table->string('excludes')->nullable();
            $table->integer('parent_id')->nullable()->comment('父节点');
            $table->string('tags')->nullable()->comment('节点标签');
            $table->string('name')->comment('节点名称');
            $table->string('rate', 11)->comment('倍率');
            $table->string('host')->comment('主机名');
            $table->string('port', 11)->comment('连接端口');
            $table->integer('server_port')->comment('服务端口');
            $table->boolean('allow_insecure')->default(false)->comment('是否允许不安全');
            $table->string('server_name')->nullable();
            $table->string('network')->nullable();
            $table->text('networkSettings')->nullable();
            $table->boolean('show')->default(false)->comment('是否显示');
            $table->integer('sort')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        $created[] = 'v2_server_trojan';

        Schema::create('v2_server_vmess', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('group_id');
            $table->string('route_id')->nullable();
            $table->string('ips')->nullable();
            $table->string('excludes')->nullable();
            $table->string('name');
            $table->integer('parent_id')->nullable();
            $table->string('host');
            $table->string('port', 11);
            $table->integer('server_port');
            $table->tinyInteger('tls')->default(0);
            $table->string('tags')->nullable();
            $table->string('rate', 11);
            $table->string('network', 11);
            $table->text('rules')->nullable();
            $table->text('networkSettings')->nullable();
            $table->text('tlsSettings')->nullable();
            $table->boolean('show')->default(false);
            $table->integer('sort')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        $created[] = 'v2_server_vmess';

        Schema::create('v2_server_vless', function (Blueprint $table) {
            $table->integer('id', true);
            $table->text('group_id');
            $table->text('route_id')->nullable();
            $table->string('ips')->nullable();
            $table->string('excludes')->nullable();
            $table->string('name');
            $table->integer('parent_id')->nullable();
            $table->string('host');
            $table->integer('port');
            $table->integer('server_port');
            $table->integer('tls');
            $table->text('tls_settings')->nullable();
            $table->string('flow', 64)->nullable();
            $table->string('network', 11);
            $table->text('network_settings')->nullable();
            $table->text('tags')->nullable();
            $table->string('rate', 11);
            $table->boolean('show')->default(false);
            $table->integer('sort')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        $created[] = 'v2_server_vless';

        Schema::create('v2_server_shadowsocks', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('group_id');
            $table->string('route_id')->nullable();
            $table->string('ips')->nullable();
            $table->string('excludes')->nullable();
            $table->integer('parent_id')->nullable();
            $table->string('tags')->nullable();
            $table->string('name');
            $table->string('rate', 11);
            $table->string('host');
            $table->string('port', 11);
            $table->integer('server_port');
            $table->string('cipher');
            $table->char('obfs', 11)->nullable();
            $table->string('obfs_settings')->nullable();
            $table->tinyInteger('show')->default(0);
            $table->integer('sort')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        $created[] = 'v2_server_shadowsocks';

        Schema::create('v2_server_hysteria', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('group_id');
            $table->string('route_id')->nullable();
            $table->string('ips')->nullable();
            $table->string('excludes')->nullable();
            $table->string('name');
            $table->integer('parent_id')->nullable();
            $table->string('host');
            $table->string('port', 11);
            $table->integer('server_port');
            $table->string('tags')->nullable();
            $table->string('rate', 11);
            $table->boolean('show')->default(false);
            $table->integer('sort')->nullable();
            $table->tinyInteger('version', false, true)->default(1)->comment('hysteria版本,Version:1\2');
            $table->boolean('is_obfs')->default(true)->comment('是否开启obfs');
            $table->string('alpn')->nullable();
            $table->integer('up_mbps');
            $table->integer('down_mbps');
            $table->string('server_name', 64)->nullable();
            $table->boolean('insecure')->default(false);
            $table->integer('created_at');
            $table->integer('updated_at');
        });
        $created[] = 'v2_server_hysteria';

    }

    private function legacyRows(): array
    {
        $types = ['trojan', 'vmess', 'vless', 'shadowsocks', 'hysteria'];
        $rows = array_fill_keys(array_map(fn ($type) => 'v2_server_' . $type, $types), []);
        $servers = DB::table('v2_server')->get()->keyBy('id');
        $legacyIds = [];
        $usedIds = [];
        foreach ($servers as $server) {
            $id = filter_var($server->code ?? $server->id, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 2147483647],
            ]);
            if (!in_array($server->type, $types, true) || $id === false || isset($usedIds[$server->type][$id])) {
                throw new RuntimeException("Cannot roll back unified server $server->id: unsupported type or conflicting legacy ID.");
            }
            $legacyIds[$server->id] = $id;
            $usedIds[$server->type][$id] = true;
        }

        foreach ($servers as $server) {
            $settings = json_decode($server->protocol_settings ?? 'null', true, 512, JSON_THROW_ON_ERROR);
            $parent = $servers->get($server->parent_id);
            if (!is_array($settings) || strlen($server->port) > 11
                || ($server->type === 'vless' && !ctype_digit($server->port))
                || ($server->parent_id !== null && (!$parent || $parent->type !== $server->type))) {
                throw new RuntimeException("Cannot roll back unified server $server->id: invalid settings, port or parent.");
            }
            if ($server->type !== 'vless' && max(strlen($server->group_ids ?? ''), strlen($server->route_ids ?? ''), strlen($server->tags ?? '')) > 255) {
                throw new RuntimeException("Cannot roll back unified server $server->id: legacy list exceeds column capacity.");
            }
            $parentId = $parent ? $legacyIds[$parent->id] : null;
            $timestamp = strtotime($server->created_at);
            $updated = strtotime($server->updated_at);
            switch ($server->type) {
                case 'trojan':
                    if ((int) ($settings['tls'] ?? 1) !== 1) {
                        throw new RuntimeException("Cannot roll back Trojan server $server->id: unsupported TLS mode.");
                    }
                    $row = [
                        'id' => $legacyIds[$server->id],
                        'group_id' => $server->group_ids ?? '[]',
                        'route_id' => $server->route_ids,
                        'parent_id' => $parentId,
                        'tags' => $server->tags,
                        'name' => $server->name,
                        'rate' => (string) $server->rate,
                        'host' => $server->host,
                        'port' => $server->port,
                        'server_port' => $server->server_port,
                        'allow_insecure' => $settings['tls_settings']['allow_insecure'] ?? $settings['allow_insecure'] ?? false,
                        'server_name' => $settings['tls_settings']['server_name'] ?? $settings['server_name'] ?? null,
                        'network' => $settings['network'] ?? null,
                        'networkSettings' => is_string($settings['network_settings'] ?? null)
                            ? $settings['network_settings']
                            : json_encode($settings['network_settings'] ?? null, JSON_THROW_ON_ERROR),
                        'show' => $server->show,
                        'sort' => $server->sort,
                        'created_at' => $timestamp,
                        'updated_at' => $updated
                    ];
                    break;
                case 'vmess':
                    $row = [
                        'id' => $legacyIds[$server->id],
                        'group_id' => $server->group_ids ?? '[]',
                        'route_id' => $server->route_ids,
                        'name' => $server->name,
                        'parent_id' => $parentId,
                        'host' => $server->host,
                        'port' => $server->port,
                        'server_port' => $server->server_port,
                        'tls' => $settings['tls'],
                        'tags' => $server->tags,
                        'rate' => (string) $server->rate,
                        'network' => $settings['network'],
                        'rules' => json_encode($settings['rules'] ?? null),
                        'networkSettings' => json_encode($settings['network_settings'] ?? null, JSON_THROW_ON_ERROR),
                        'tlsSettings' => json_encode($settings['tls_settings'] ?? null, JSON_THROW_ON_ERROR),
                        'show' => $server->show,
                        'sort' => $server->sort,
                        'created_at' => $timestamp,
                        'updated_at' => $updated
                    ];
                    break;
                case 'vless':
                    $tlsSettings = $settings['tls_settings'] ?? [];
                    if ((int) $settings['tls'] === 2) {
                        $reality = $settings['reality_settings'] ?? [];
                        if (isset($reality['dest'])) {
                            $dest = parse_url('tcp://' . $reality['dest']);
                            $reality['server_name'] ??= $dest['host'] ?? null;
                            $reality['server_port'] ??= $dest['port'] ?? null;
                        }
                        if (empty($reality['server_name']) || empty($reality['server_port'])
                            || empty($reality['public_key']) || empty($reality['private_key'])) {
                            throw new RuntimeException("Cannot roll back Reality server $server->id: incomplete settings.");
                        }
                        $tlsSettings = array_merge($tlsSettings, array_intersect_key($reality, array_flip([
                            'public_key', 'short_id', 'server_name', 'server_port', 'private_key',
                        ])));
                    }

                    $row = [
                        'id' => $legacyIds[$server->id],
                        'group_id' => $server->group_ids ?? '[]',
                        'route_id' => $server->route_ids,
                        'name' => $server->name,
                        'parent_id' => $parentId,
                        'host' => $server->host,
                        'port' => $server->port,
                        'server_port' => $server->server_port,
                        'tls' => $settings['tls'],
                        'tls_settings' => json_encode($tlsSettings),
                        'flow' => $settings['flow'] ?? null,
                        'network' => $settings['network'],
                        'network_settings' => json_encode($settings['network_settings'] ?? null, JSON_THROW_ON_ERROR),
                        'tags' => $server->tags,
                        'rate' => (string) $server->rate,
                        'show' => $server->show,
                        'sort' => $server->sort,
                        'created_at' => $timestamp,
                        'updated_at' => $updated
                    ];
                    break;
                case 'shadowsocks':
                    $row = [
                        'id' => $legacyIds[$server->id],
                        'group_id' => $server->group_ids ?? '[]',
                        'route_id' => $server->route_ids,
                        'parent_id' => $parentId,
                        'tags' => $server->tags,
                        'name' => $server->name,
                        'rate' => (string) $server->rate,
                        'host' => $server->host,
                        'port' => $server->port,
                        'server_port' => $server->server_port,
                        'cipher' => $settings['cipher'],
                        'obfs' => $settings['obfs'] ?? null,
                        'obfs_settings' => json_encode($settings['obfs_settings'] ?? null, JSON_THROW_ON_ERROR),
                        'show' => (int) $server->show,
                        'sort' => $server->sort,
                        'created_at' => $timestamp,
                        'updated_at' => $updated
                    ];
                    break;
                case 'hysteria':
                    $obfs = $settings['obfs'] ?? [];
                    if (!empty($obfs['open']) && (($obfs['type'] ?? null) !== 'salamander'
                        || ($obfs['password'] ?? null) !== Helper::getServerKey($timestamp, 16))) {
                        throw new RuntimeException("Cannot roll back Hysteria server $server->id: custom obfuscation.");
                    }
                    $row = [
                        'id' => $legacyIds[$server->id],
                        'group_id' => $server->group_ids ?? '[]',
                        'route_id' => $server->route_ids,
                        'name' => $server->name,
                        'parent_id' => $parentId,
                        'host' => $server->host,
                        'port' => $server->port,
                        'server_port' => $server->server_port,
                        'tags' => $server->tags,
                        'rate' => (string) $server->rate,
                        'show' => $server->show,
                        'sort' => $server->sort,
                        'version' => $settings['version'] ?? 1,
                        'is_obfs' => $obfs['open'] ?? false,
                        'up_mbps' => $settings['bandwidth']['up'],
                        'down_mbps' => $settings['bandwidth']['down'],
                        'server_name' => $settings['tls']['server_name'] ?? null,
                        'insecure' => $settings['tls']['allow_insecure'] ?? false,
                        'created_at' => $timestamp,
                        'updated_at' => $updated
                    ];
                    break;
            }
            $rows['v2_server_' . $server->type][] = $row;
        }

        return $rows;
    }
};
