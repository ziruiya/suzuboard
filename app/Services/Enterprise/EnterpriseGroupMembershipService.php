<?php

namespace App\Services\Enterprise;

use App\Models\Server;
use Illuminate\Support\Facades\DB;

/**
 * SB-ENT-INV-01 hard constraint 2 (from audit V06/AUD-025): appending a
 * group to a node's group_ids must go through a row-locked merge so two
 * concurrent assignments can never overwrite each other's grants. Whole-row
 * overwrites of group_ids for shared (VIP) nodes are forbidden.
 *
 * All methods must be called inside a DB transaction.
 */
class EnterpriseGroupMembershipService
{
    /**
     * Append $groupId to group_ids of every server in $serverIds.
     * Returns the number of servers actually changed.
     */
    public function addGroupToServers(int $groupId, array $serverIds): int
    {
        $changed = 0;
        $servers = Server::whereIn('id', $serverIds)->lockForUpdate()->get();
        foreach ($servers as $server) {
            $ids = array_values(array_map('strval', $server->group_ids ?? []));
            if (in_array((string) $groupId, $ids, true)) {
                continue;
            }
            $ids[] = (string) $groupId;
            $server->group_ids = $ids;
            $server->save();
            $changed++;
        }
        return $changed;
    }

    /** Remove $groupId from group_ids of every server in $serverIds. */
    public function removeGroupFromServers(int $groupId, array $serverIds): int
    {
        $changed = 0;
        $servers = Server::whereIn('id', $serverIds)->lockForUpdate()->get();
        foreach ($servers as $server) {
            $ids = array_values(array_map('strval', $server->group_ids ?? []));
            if (!in_array((string) $groupId, $ids, true)) {
                continue;
            }
            $server->group_ids = array_values(array_diff($ids, [(string) $groupId]));
            $server->save();
            $changed++;
        }
        return $changed;
    }

    /**
     * The VIP node set: servers exclusive to the VIP/SVIP group — visible to
     * that group and not to the ordinary group. The VIP group id comes from
     * the `enterprise_vip_group_id` admin setting (default 2 = SVIP group).
     */
    public function vipServerIds(): array
    {
        $vipGroupId = (string) ((int) admin_setting('enterprise_vip_group_id', 2));
        return Server::query()
            ->whereJsonContains('group_ids', $vipGroupId)
            ->get()
            ->filter(function (Server $server) use ($vipGroupId) {
                $ids = array_map('strval', $server->group_ids ?? []);
                // Exclusive to the VIP group: no ordinary-group (1) access.
                return in_array($vipGroupId, $ids, true) && !in_array('1', $ids, true);
            })
            ->pluck('id')
            ->all();
    }

    public function addGroupToVipServers(int $groupId): int
    {
        $ids = $this->vipServerIds();
        if (!$ids) {
            return 0;
        }
        return $this->addGroupToServers($groupId, $ids);
    }

    public function removeGroupFromVipServers(int $groupId): int
    {
        // Removal must also reach servers that meanwhile gained other groups,
        // so scan every server still carrying the kit group instead of only
        // the current VIP set.
        $ids = Server::query()
            ->whereJsonContains('group_ids', (string) $groupId)
            ->pluck('id')
            ->all();
        if (!$ids) {
            return 0;
        }
        return $this->removeGroupFromServers($groupId, $ids);
    }
}
