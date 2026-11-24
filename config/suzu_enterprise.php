<?php

return [
    /*
     * csmzzmb entry-rule capacity guardrails (SB-ENT-INV-01 batch 2, D06 §6.3).
     *
     * Occupancy counts every registered csmzzmb rule in
     * suzu_enterprise_inventory_rules that is not DISABLED: READY/RESERVED
     * rows are the pre-built rule stock and IN_USE rows are rules already
     * consumed by kits (including kits assigned to customers). Both occupy
     * a slot on the provider side, so both count.
     *
     * At >= warn percent an alert is raised; at >= stop percent new csmzzmb
     * rule registrations are refused until occupancy drops.
     */
    'rule_capacity_total' => (int) env('SUZU_ENT_RULE_CAPACITY_TOTAL', 100),
    'rule_capacity_warn_percent' => (int) env('SUZU_ENT_RULE_CAPACITY_WARN_PERCENT', 80),
    'rule_capacity_stop_percent' => (int) env('SUZU_ENT_RULE_CAPACITY_STOP_PERCENT', 90),
];
