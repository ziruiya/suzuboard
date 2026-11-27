<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\NodeSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NodeUserSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 10;

    public function __construct(
        private readonly int $userId,
        private readonly string $action,
        private readonly ?int $oldGroupId = null,
        private readonly array $groupIds = [],
    ) {
        $this->onQueue('node_sync');
    }

    public static function refreshGroups(array $groupIds): void
    {
        self::dispatch(0, 'refresh_groups', groupIds: $groupIds);
    }

    public function handle(): void
    {
        if ($this->action === 'refresh_groups') {
            foreach ($this->groupIds as $groupId) {
                NodeSyncService::notifyUsersUpdatedByGroup($groupId);
            }
            return;
        }

        $user = User::find($this->userId);

        if ($this->action === 'updated' || $this->action === 'created') {
            if ($this->oldGroupId) {
                NodeSyncService::notifyUserRemovedFromGroup($this->userId, $this->oldGroupId);
            }
            if ($user) {
                NodeSyncService::notifyUserChanged($user);
            }
        } elseif ($this->action === 'deleted') {
            if ($this->oldGroupId) {
                NodeSyncService::notifyUserRemovedFromGroup($this->userId, $this->oldGroupId);
            }
        }
    }
}
