<?php

namespace App\Observers;

use App\Models\Server;
use App\Services\NodeSyncService;
use App\Support\CommittedModelUpdates;

class ServerObserver
{
    private const CONFIG_FIELDS = [
        'server_port', 'protocol_settings', 'type', 'route_ids', 'custom_outbounds', 'custom_routes', 'cert_config',
    ];

    public function __construct(private readonly CommittedModelUpdates $updates)
    {
    }

    public function created(Server $server): void
    {
        $machineId = $server->machine_id;
        if ($machineId) {
            $server->getConnection()->afterCommit(fn () => NodeSyncService::notifyMachineNodesChanged($machineId));
        }
    }

    public function updated(Server $server): void
    {
        $changed = array_intersect_key($server->getChanges(), array_flip([
            'group_ids', 'machine_id', 'enabled', ...self::CONFIG_FIELDS,
        ]));
        if (!$changed) {
            return;
        }

        $this->updates->record(
            $server,
            ['machine_id' => $server->getOriginal('machine_id'), 'enabled' => $server->getOriginal('enabled')],
            $changed,
            function (Server $final, array $original, array $changed): void {
                if (array_key_exists('group_ids', $changed)) {
                    NodeSyncService::notifyFullSync($final->id);
                } elseif (array_intersect_key($changed, array_flip(self::CONFIG_FIELDS))) {
                    NodeSyncService::notifyConfigUpdated($final->id);
                }

                $oldMachineId = $original['machine_id'];
                if ($oldMachineId !== $final->machine_id && $oldMachineId) {
                    NodeSyncService::notifyMachineNodesChanged($oldMachineId);
                }
                if ($final->machine_id && ($oldMachineId !== $final->machine_id || $original['enabled'] !== $final->enabled)) {
                    NodeSyncService::notifyMachineNodesChanged($final->machine_id);
                }
            },
        );
    }

    public function deleted(Server $server): void
    {
        $machineId = $server->machine_id;
        if ($machineId) {
            $server->getConnection()->afterCommit(fn () => NodeSyncService::notifyMachineNodesChanged($machineId));
        }
    }
}
