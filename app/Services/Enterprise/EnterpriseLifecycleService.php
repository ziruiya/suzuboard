<?php

namespace App\Services\Enterprise;

use App\Models\EnterpriseInventoryAlert;
use App\Models\EnterpriseInventoryKit;
use App\Models\EnterpriseInventoryLog;
use App\Models\EnterpriseOrderIntent;
use App\Models\EnterprisePurpose;
use App\Models\Order;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Time-driven kit lifecycle: HELD timeouts (E05), expiry → 7-day grace →
 * quarantine, and the 24h waiting-queue escalation (E06 revised, D02: no
 * refunds anywhere in this flow).
 */
class EnterpriseLifecycleService
{
    public function __construct(
        private EnterpriseInventoryService $inventory,
        private EnterpriseGroupMembershipService $membership,
        private EnterpriseAssignmentService $assignment,
    ) {
    }

    /** Release HELD kits whose 30-minute payment window lapsed unpaid (E05). */
    public function releaseExpiredHolds(): int
    {
        $released = 0;
        $intents = EnterpriseOrderIntent::where('status', EnterpriseOrderIntent::STATUS_HELD)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<', now())
            ->get();
        foreach ($intents as $intent) {
            $order = $intent->order_id ? Order::find($intent->order_id) : null;
            if ($order && in_array((int) $order->status, [Order::STATUS_PROCESSING, Order::STATUS_COMPLETED], true)) {
                continue; // payment won the race; assignment owns the kit now
            }
            $this->assignment->releaseHold($intent, 'hold expired unpaid');
            $released++;
        }
        return $released;
    }

    /** ASSIGNED kits whose owner's plan expired move into the 7-day grace window. */
    public function expireAssignedKits(): int
    {
        $moved = 0;
        $kits = EnterpriseInventoryKit::whereIn('status', [
            EnterpriseInventoryKit::STATUS_ASSIGNED,
            EnterpriseInventoryKit::STATUS_ASSIGNED_LEGACY,
        ])->whereNotNull('current_user_id')->get();
        foreach ($kits as $kit) {
            $user = User::find($kit->current_user_id);
            if (!$user || $user->expired_at === null || $user->expired_at >= time()) {
                continue;
            }
            DB::transaction(function () use ($kit, $user) {
                $kit = EnterpriseInventoryKit::lockForUpdate()->find($kit->id);
                if (!$kit || $kit->status === EnterpriseInventoryKit::STATUS_GRACE_HELD) {
                    return;
                }
                $kit->grace_until = \Carbon\Carbon::createFromTimestamp($user->expired_at)->addDays(7);
                $kit->save();
                $this->inventory->transition($kit, EnterpriseInventoryKit::STATUS_GRACE_HELD, 'kit.grace', "user {$user->id} expired at {$user->expired_at}");
            });
            $moved++;
        }
        return $moved;
    }

    /** Grace window over (day 8+): quarantine the kit and detach the customer. */
    public function quarantineStaleGraceKits(): int
    {
        $done = 0;
        $kits = EnterpriseInventoryKit::where('status', EnterpriseInventoryKit::STATUS_GRACE_HELD)
            ->whereNotNull('grace_until')
            ->where('grace_until', '<', now())
            ->get();
        foreach ($kits as $kit) {
            DB::transaction(function () use ($kit) {
                $kit = EnterpriseInventoryKit::lockForUpdate()->find($kit->id);
                if (!$kit || $kit->status !== EnterpriseInventoryKit::STATUS_GRACE_HELD) {
                    return;
                }
                $this->membership->removeGroupFromVipServers($kit->stock_group_id);
                Server::whereIn('id', $kit->nodes()->pluck('server_id')->filter()->all())
                    ->update(['show' => false]);
                $user = $kit->current_user_id ? User::lockForUpdate()->find($kit->current_user_id) : null;
                if ($user && (int) $user->enterprise_kit_id === (int) $kit->id) {
                    $user->enterprise_kit_id = null;
                    $user->save();
                }
                $kit->current_user_id = null;
                $kit->current_order_id = null;
                $kit->grace_until = null;
                $kit->save();
                $this->inventory->transition($kit, EnterpriseInventoryKit::STATUS_QUARANTINED, 'kit.quarantined', 'grace window elapsed');
                $this->inventory->refreshLevel($kit->purpose_id);
            });
            $done++;
        }
        return $done;
    }

    /** Paid-but-waiting orders older than 24h escalate (E06 revised — alert, never refund). */
    public function escalateWaitingIntents(): int
    {
        $count = 0;
        $intents = EnterpriseOrderIntent::where('status', EnterpriseOrderIntent::STATUS_PAID_WAITING_INVENTORY)
            ->whereNotNull('waiting_since')
            ->where('waiting_since', '<', now()->subHours(24))
            ->where(function ($q) {
                $q->whereNull('last_alert_at')->orWhere('last_alert_at', '<', now()->subHours(24));
            })
            ->get();
        foreach ($intents as $intent) {
            EnterpriseInventoryAlert::raise(
                $intent->purpose_id,
                $intent->kit_id,
                EnterpriseInventoryAlert::TYPE_WAITING_TOO_LONG,
                "Order intent #{$intent->id} (user {$intent->user_id}) has waited over 24h for a kit of purpose {$intent->purpose_id}; still queued (no refund per policy)"
            );
            $intent->last_alert_at = now();
            $intent->save();
            EnterpriseInventoryLog::record($intent->kit_id, 'intent.waiting_escalated', "intent {$intent->id}");
            $count++;
        }
        return $count;
    }

    /** Hand READY kits to the oldest waiting intents, FIFO per purpose. */
    public function drainWaitingIntents(): int
    {
        $assigned = 0;
        foreach (EnterprisePurpose::where('is_active', true)->get() as $purpose) {
            $guard = 0;
            while ($guard++ < 50) {
                $intent = EnterpriseOrderIntent::where('purpose_id', $purpose->id)
                    ->where('status', EnterpriseOrderIntent::STATUS_PAID_WAITING_INVENTORY)
                    ->orderBy('waiting_since')
                    ->orderBy('id')
                    ->first();
                if (!$intent) {
                    break;
                }
                if (!$this->assignment->assignWaitingIntent($intent)) {
                    // SB-MONEY-008: a false return normally means "no
                    // READY kit" and the whole purpose queue must wait.
                    // But if the attempt closed the head intent itself
                    // (its subscription lapsed and it was moved to
                    // manual handling), later intents may still be
                    // deliverable — keep scanning instead of letting
                    // one dead intent block the purpose indefinitely.
                    $headStatus = EnterpriseOrderIntent::whereKey($intent->id)->value('status');
                    if ($headStatus === EnterpriseOrderIntent::STATUS_PAID_WAITING_INVENTORY) {
                        break;
                    }
                    continue;
                }
                $assigned++;
            }
        }
        return $assigned;
    }
}
