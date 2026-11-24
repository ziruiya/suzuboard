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

    /*
     * Bounded-retry backoff for post-assignment delivery verification
     * (seconds slept after each failed attempt; attempts = count + 1).
     * The agent needs a sync window after assignment before the tunnels
     * answer; a single immediate probe misjudges that lag as failure.
     * Default total window ≈ 210 s of sleeps plus probe time.
     */
    'delivery_verify_backoff_seconds' => array_map('intval', explode(',', (string) env('SUZU_ENT_DELIVERY_VERIFY_BACKOFF', '20,40,60,90'))),

    /*
     * Same doctrine for the kit builder's post-materialize verification:
     * sleeps happen only after a FAILED attempt (the agent-sync-lag
     * signal), never between passing attempts.
     */
    'build_verify_backoff_seconds' => array_map('intval', explode(',', (string) env('SUZU_ENT_BUILD_VERIFY_BACKOFF', '15,30,45,60'))),
];
