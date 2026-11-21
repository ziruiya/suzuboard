<?php

namespace App\Services;

use App\Exceptions\SuzuEndpointException;
use App\Models\SuzuEndpointBinding;
use App\Models\SuzuEndpointChange;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare DNS cutover for endpoint bindings.
 *
 * Safety rules:
 *  - The API token comes from server config (env) only.
 *  - Every write presents the binding's current revision (optimistic
 *    lock); it is checked before any API call and re-checked under a row
 *    lock when the result is committed.
 *  - The record's current content is read and stored on the audit row
 *    before the change, the new content is read back after the change,
 *    and any completed cutover can be rolled back to the stored value.
 *  - Repeating a cutover to the IP already in place is a no-op
 *    (idempotent): no API call, no new audit row.
 *  - Failed attempts still leave an audit row marked rollback_status
 *    'failed' — the trail must show attempts, not only successes.
 */
class SuzuDnsCutoverService
{
    /**
     * Point the binding's DNS record at $newIp.
     *
     * @return array{binding: SuzuEndpointBinding, change: ?SuzuEndpointChange, idempotent: bool}
     */
    public function cutover(SuzuEndpointBinding $binding, string $newIp, int $expectedRevision, ?int $actorId = null, ?array $precheckResult = null): array
    {
        if (!filter_var($newIp, FILTER_VALIDATE_IP)) {
            throw new SuzuEndpointException("invalid target ip: {$newIp}");
        }
        $this->assertBindingReady($binding);

        if ($binding->revision !== $expectedRevision) {
            throw new SuzuEndpointException(
                "revision conflict: binding is at {$binding->revision}, request presented {$expectedRevision}; reload and retry"
            );
        }
        if ($binding->current_entry_ip === $newIp) {
            return ['binding' => $binding, 'change' => null, 'idempotent' => true];
        }

        $oldIp = $this->readRecordContent($binding);
        if ($oldIp === null) {
            throw new SuzuEndpointException('could not read current DNS record content; aborting cutover');
        }

        $change = SuzuEndpointChange::create([
            'contract_id' => $binding->contract_id,
            'action' => SuzuEndpointChange::ACTION_DNS_CUTOVER,
            'actor_id' => $actorId,
            'from_revision' => $binding->revision,
            'to_revision' => $binding->revision + 1,
            'old_binding' => array_merge($binding->auditSnapshot(), ['current_entry_ip' => $oldIp]),
            'precheck_result' => $precheckResult,
            'rollback_status' => SuzuEndpointChange::ROLLBACK_NONE,
        ]);

        try {
            $this->updateRecordContent($binding, $newIp);
            $readBack = $this->readRecordContent($binding);
            if ($readBack !== $newIp) {
                throw new SuzuEndpointException("cutover read-back mismatch: record holds {$readBack}, expected {$newIp}");
            }
        } catch (\Throwable $e) {
            $change->postcheck_result = ['verified' => false, 'error' => $e->getMessage()];
            $change->rollback_status = SuzuEndpointChange::ROLLBACK_FAILED;
            $change->save();
            throw $e instanceof SuzuEndpointException ? $e : new SuzuEndpointException($e->getMessage());
        }

        $binding = $this->commitBindingIp($binding, $newIp, $expectedRevision, $change);

        $change->new_binding = $binding->auditSnapshot();
        $change->postcheck_result = ['read_back' => $newIp, 'verified' => true];
        $change->save();

        return ['binding' => $binding, 'change' => $change, 'idempotent' => false];
    }

    /**
     * Roll a completed cutover back to the DNS value it replaced.
     *
     * @return array{binding: SuzuEndpointBinding, change: SuzuEndpointChange}
     */
    public function rollback(SuzuEndpointChange $cutoverChange, int $expectedRevision, ?int $actorId = null): array
    {
        if ($cutoverChange->action !== SuzuEndpointChange::ACTION_DNS_CUTOVER) {
            throw new SuzuEndpointException('only dns_cutover changes can be rolled back');
        }
        if ($cutoverChange->rollback_status === SuzuEndpointChange::ROLLBACK_DONE) {
            throw new SuzuEndpointException('this cutover has already been rolled back');
        }
        if (($cutoverChange->postcheck_result['verified'] ?? false) !== true) {
            throw new SuzuEndpointException('only a verified cutover can be rolled back');
        }
        $oldIp = $cutoverChange->old_binding['current_entry_ip'] ?? null;
        if (!$oldIp) {
            throw new SuzuEndpointException('cutover change has no stored previous value to roll back to');
        }

        $binding = SuzuEndpointBinding::where('contract_id', $cutoverChange->contract_id)->first();
        if (!$binding) {
            throw new SuzuEndpointException('binding for cutover contract not found');
        }
        $this->assertBindingReady($binding);
        if ($binding->revision !== $expectedRevision) {
            throw new SuzuEndpointException(
                "revision conflict: binding is at {$binding->revision}, request presented {$expectedRevision}; reload and retry"
            );
        }

        $change = SuzuEndpointChange::create([
            'contract_id' => $binding->contract_id,
            'action' => SuzuEndpointChange::ACTION_DNS_ROLLBACK,
            'actor_id' => $actorId,
            'from_revision' => $binding->revision,
            'to_revision' => $binding->revision + 1,
            'old_binding' => $binding->auditSnapshot(),
            'related_change_id' => $cutoverChange->id,
            'rollback_status' => SuzuEndpointChange::ROLLBACK_NONE,
        ]);

        try {
            $this->updateRecordContent($binding, (string) $oldIp);
            $readBack = $this->readRecordContent($binding);
            if ($readBack !== $oldIp) {
                throw new SuzuEndpointException("rollback read-back mismatch: record holds {$readBack}, expected {$oldIp}");
            }
        } catch (\Throwable $e) {
            $change->postcheck_result = ['verified' => false, 'error' => $e->getMessage()];
            $change->rollback_status = SuzuEndpointChange::ROLLBACK_FAILED;
            $change->save();
            throw $e instanceof SuzuEndpointException ? $e : new SuzuEndpointException($e->getMessage());
        }

        $binding = $this->commitBindingIp($binding, (string) $oldIp, $expectedRevision, $change);

        $change->new_binding = $binding->auditSnapshot();
        $change->postcheck_result = ['read_back' => $oldIp, 'verified' => true];
        $change->save();

        $cutoverChange->rollback_status = SuzuEndpointChange::ROLLBACK_DONE;
        $cutoverChange->save();

        return ['binding' => $binding, 'change' => $change];
    }

    /** Current content of the binding's DNS record, or null if unreadable. */
    public function readRecordContent(SuzuEndpointBinding $binding): ?string
    {
        $response = $this->request()->get($this->recordUrl($binding));
        if (!$response->successful()) {
            return null;
        }
        $content = $response->json('result.content');
        return $content !== null ? (string) $content : null;
    }

    /**
     * Commit a verified DNS value to the binding under a row lock,
     * re-checking the optimistic-lock revision.
     */
    private function commitBindingIp(SuzuEndpointBinding $binding, string $ip, int $expectedRevision, SuzuEndpointChange $change): SuzuEndpointBinding
    {
        return DB::transaction(function () use ($binding, $ip, $expectedRevision, $change) {
            $locked = SuzuEndpointBinding::where('id', $binding->id)->lockForUpdate()->first();
            if (!$locked) {
                throw new SuzuEndpointException('binding disappeared during cutover');
            }
            if ($locked->revision !== $expectedRevision) {
                $change->postcheck_result = ['verified' => false, 'error' => 'revision moved during cutover; DNS already changed, binding left untouched'];
                $change->rollback_status = SuzuEndpointChange::ROLLBACK_FAILED;
                $change->save();
                throw new SuzuEndpointException('revision moved during cutover; DNS was changed but the binding was not updated — reconcile manually');
            }
            $locked->current_entry_ip = $ip;
            $locked->revision = $locked->revision + 1;
            $locked->save();
            return $locked;
        });
    }

    private function updateRecordContent(SuzuEndpointBinding $binding, string $newIp): void
    {
        $response = $this->request()->patch($this->recordUrl($binding), ['content' => $newIp]);
        if (!$response->successful() || $response->json('success') !== true) {
            throw new SuzuEndpointException(
                'cloudflare update failed: HTTP ' . $response->status() . ' ' . substr((string) $response->body(), 0, 300)
            );
        }
    }

    private function recordUrl(SuzuEndpointBinding $binding): string
    {
        $base = rtrim((string) config('suzu_endpoint.cloudflare.base_url'), '/');
        return "{$base}/zones/{$binding->dns_zone_id}/dns_records/{$binding->dns_record_id}";
    }

    private function request()
    {
        $token = (string) config('suzu_endpoint.cloudflare.api_token', '');
        if ($token === '') {
            throw new SuzuEndpointException('cloudflare api token is not configured on the server (SUZU_CF_API_TOKEN)');
        }
        return Http::withToken($token)
            ->acceptJson()
            ->timeout((int) config('suzu_endpoint.cloudflare.timeout', 15));
    }

    private function assertBindingReady(SuzuEndpointBinding $binding): void
    {
        if (!$binding->dns_zone_id || !$binding->dns_record_id) {
            throw new SuzuEndpointException('binding has no DNS zone/record id registered; register it before cutover');
        }
        if ($binding->entry_mode !== SuzuEndpointBinding::ENTRY_DNS) {
            throw new SuzuEndpointException("binding entry_mode is {$binding->entry_mode}; DNS cutover only applies to dns-mode bindings");
        }
    }
}
