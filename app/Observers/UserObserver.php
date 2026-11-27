<?php

namespace App\Observers;

use App\Jobs\NodeUserSyncJob;
use App\Models\User;
use App\Services\TrafficResetService;
use App\Support\CommittedModelUpdates;

class UserObserver
{
  private const SYNC_FIELDS = [
    'group_id', 'uuid', 'speed_limit', 'device_limit', 'banned',
    'expired_at', 'transfer_enable', 'u', 'd', 'plan_id',
  ];

  public function __construct(
    private readonly TrafficResetService $trafficResetService,
    private readonly CommittedModelUpdates $updates,
  ) {
  }

  public function updated(User $user): void
  {
    $changed = array_intersect_key($user->getChanges(), array_flip(self::SYNC_FIELDS));
    if (!$changed) {
      return;
    }

    $oldGroupId = $user->getOriginal('group_id');
    $this->updates->record(
      $user,
      ['group_id' => $oldGroupId === null ? null : (int) $oldGroupId],
      $changed,
      function (User $final, array $original, array $changed): void {
        if (array_key_exists('plan_id', $changed) || array_key_exists('expired_at', $changed)) {
          $this->recalculateNextResetAt($final);
        }

        $oldGroupId = $original['group_id'];
        $newGroupId = $final->group_id === null ? null : (int) $final->group_id;
        NodeUserSyncJob::dispatch($final->id, 'updated', $oldGroupId !== $newGroupId ? $oldGroupId : null);
      },
    );
  }

  public function created(User $user): void
  {
    $user->getConnection()->afterCommit(function () use ($user) {
      if ($user->exists) {
        $user->refresh();
        $this->recalculateNextResetAt($user);
        NodeUserSyncJob::dispatch($user->id, 'created');
      }
    });
  }

  public function deleted(User $user): void
  {
    $id = $user->id;
    $groupId = $user->group_id;
    if ($groupId) {
      $user->getConnection()->afterCommit(fn () => NodeUserSyncJob::dispatch($id, 'deleted', $groupId));
    }
  }

  private function recalculateNextResetAt(User $user): void
  {
    $user->next_reset_at = $this->trafficResetService->calculateNextResetTime($user)?->timestamp;
    $user->saveQuietly();
  }
}
