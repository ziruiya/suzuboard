<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseInventoryKit;
use App\Models\EnterpriseInventoryNode;
use App\Models\Plan;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Http\Request;

/**
 * SB-ENT-INV-01 member-center surface (D05): the customer's own dedicated
 * lines — entry address/port, expiry, traffic and device allowance.
 * Deliberately exposes NO secrets: no uuid/password, no keys, no internal
 * group ids.
 */
class EnterpriseLineController extends Controller
{
    public function lines(Request $request, UserService $userService)
    {
        $user = User::find($request->user()->id);
        $kit = $user?->enterprise_kit_id
            ? EnterpriseInventoryKit::with(['purpose', 'nodes.server'])->find($user->enterprise_kit_id)
            : null;
        if (!$kit || !in_array($kit->status, [
            EnterpriseInventoryKit::STATUS_ASSIGNED,
            EnterpriseInventoryKit::STATUS_ASSIGNED_LEGACY,
            EnterpriseInventoryKit::STATUS_GRACE_HELD,
        ], true)) {
            return $this->success(['has_enterprise' => false]);
        }

        $presentNode = function (string $role) use ($kit) {
            $node = $kit->nodes->firstWhere('node_role', $role);
            if (!$node) {
                return null;
            }
            return [
                'name' => $node->server?->name,
                'entry_address' => $node->dns_alias,
                'entry_port' => (int) $node->port,
            ];
        };

        $plan = $user->plan_id ? Plan::find($user->plan_id) : null;
        $total = (int) $user->transfer_enable;
        $used = (int) $user->u + (int) $user->d;

        return $this->success([
            'has_enterprise' => true,
            'plan_name' => $plan?->name,
            'purpose_name' => $kit->purpose?->name,
            'purpose_code' => $kit->purpose?->code,
            'status' => $kit->status,
            'primary' => $presentNode(EnterpriseInventoryNode::ROLE_PRIMARY),
            'backup' => $presentNode(EnterpriseInventoryNode::ROLE_BACKUP),
            'expired_at' => $user->expired_at,
            'transfer_enable' => $total,
            'transfer_used' => $used,
            'transfer_remaining' => max(0, $total - $used),
            'device_limit' => $user->device_limit,
        ]);
    }
}
