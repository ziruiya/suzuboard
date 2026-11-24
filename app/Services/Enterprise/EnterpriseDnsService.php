<?php

namespace App\Services\Enterprise;

/**
 * DNS alias provisioning for kit entry names. Aliases must resolve to the
 * entry provider's address so client subscriptions stay stable for the
 * kit's whole life (SB-Endpoint-01 frozen contract).
 */
interface EnterpriseDnsService
{
    /**
     * Ensure $alias is a DNS name pointing at $targetHost.
     * Returns true when the record is live, false when it was only recorded
     * as pending (no API token configured) and ops must create it.
     */
    public function ensureAlias(string $alias, string $targetHost): bool;
}
