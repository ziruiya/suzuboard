<?php

namespace App\Services\Enterprise;

use App\Models\EnterpriseInventoryAlert;
use App\Models\EnterpriseInventoryRule;
use App\Models\EnterprisePurpose;

/**
 * csmzzmb rule-capacity guardrail (SB-ENT-INV-01 batch 2, D06 §6.3).
 *
 * Occupancy = every registered csmzzmb rule that is not DISABLED:
 * READY/RESERVED rows are the pre-built rule stock, IN_USE rows are rules
 * already consumed by kits (including kits assigned to customers). Both
 * occupy a slot on the provider side, so both count toward the plan cap
 * (config suzu_enterprise.rule_capacity_total, default 100).
 *
 * Thresholds (config, percentages of the cap):
 *  - >= warn (default 80%): a rule_capacity_warn alert is raised.
 *  - >= stop (default 90%): a rule_capacity_stop alert is raised and new
 *    csmzzmb rule registrations are refused (assertCanRegister throws).
 *
 * Kit building is deliberately NOT blocked at the stop threshold:
 * consuming an already-registered rule (READY -> IN_USE) does not change
 * occupancy, so stopping builds would not protect the cap — registration
 * is the only point where occupancy grows.
 */
class EnterpriseCapacityService
{
    public const STATE_OK = 'ok';
    public const STATE_WARN = 'warn';
    public const STATE_STOP = 'stop';

    public function total(): int
    {
        return max(1, (int) config('suzu_enterprise.rule_capacity_total', 100));
    }

    public function warnPercent(): int
    {
        return (int) config('suzu_enterprise.rule_capacity_warn_percent', 80);
    }

    public function stopPercent(): int
    {
        return (int) config('suzu_enterprise.rule_capacity_stop_percent', 90);
    }

    /** Registered csmzzmb rules occupying a provider slot (stock + assigned). */
    public function registeredCount(): int
    {
        return EnterpriseInventoryRule::where('provider', EnterprisePurpose::PROVIDER_CSMZZMB)
            ->where('status', '!=', EnterpriseInventoryRule::STATUS_DISABLED)
            ->count();
    }

    public function occupancyPercent(): float
    {
        return $this->registeredCount() / $this->total() * 100;
    }

    public function state(): string
    {
        $percent = $this->occupancyPercent();
        if ($percent >= $this->stopPercent()) {
            return self::STATE_STOP;
        }
        if ($percent >= $this->warnPercent()) {
            return self::STATE_WARN;
        }
        return self::STATE_OK;
    }

    /**
     * Current capacity snapshot.
     *
     * @return array{provider: string, registered: int, total: int, percent: float, state: string, warn_percent: int, stop_percent: int}
     */
    public function snapshot(): array
    {
        $registered = $this->registeredCount();
        $total = $this->total();
        return [
            'provider' => EnterprisePurpose::PROVIDER_CSMZZMB,
            'registered' => $registered,
            'total' => $total,
            'percent' => round($registered / $total * 100, 1),
            'state' => $this->state(),
            'warn_percent' => $this->warnPercent(),
            'stop_percent' => $this->stopPercent(),
        ];
    }

    /**
     * Snapshot + threshold alerts. Alert raising is deduplicated by
     * EnterpriseInventoryAlert::raise (one OPEN alert per type).
     *
     * @return array{provider: string, registered: int, total: int, percent: float, state: string, warn_percent: int, stop_percent: int}
     */
    public function evaluate(): array
    {
        $snapshot = $this->snapshot();
        if ($snapshot['state'] === self::STATE_STOP) {
            EnterpriseInventoryAlert::raise(null, null, EnterpriseInventoryAlert::TYPE_RULE_CAPACITY_STOP, "csmzzmb rule occupancy {$snapshot['registered']}/{$snapshot['total']} ({$snapshot['percent']}%) reached the {$snapshot['stop_percent']}% stop threshold; new rule registrations are refused");
        } elseif ($snapshot['state'] === self::STATE_WARN) {
            EnterpriseInventoryAlert::raise(null, null, EnterpriseInventoryAlert::TYPE_RULE_CAPACITY_WARN, "csmzzmb rule occupancy {$snapshot['registered']}/{$snapshot['total']} ({$snapshot['percent']}%) reached the {$snapshot['warn_percent']}% warning threshold");
        }
        return $snapshot;
    }

    /**
     * Guard for the rule-registration path: throws when occupancy is at or
     * above the stop threshold.
     */
    public function assertCanRegister(): void
    {
        $snapshot = $this->snapshot();
        if ($snapshot['state'] === self::STATE_STOP) {
            throw new \RuntimeException("csmzzmb rule capacity stopped: {$snapshot['registered']}/{$snapshot['total']} ({$snapshot['percent']}%) >= {$snapshot['stop_percent']}%; new rule registrations are refused");
        }
    }
}
