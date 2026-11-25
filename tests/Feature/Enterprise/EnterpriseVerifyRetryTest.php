<?php

namespace Tests\Feature\Enterprise;

use App\Models\EnterpriseInventoryAlert;
use App\Models\EnterpriseInventoryKit;
use App\Models\EnterpriseInventoryLog;
use App\Models\EnterpriseInventoryNode;
use App\Models\EnterpriseInventoryRule;
use App\Models\EnterpriseOrderIntent;
use App\Models\EnterprisePurpose;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\Enterprise\EnterpriseInventoryHealthService;
use App\Services\Enterprise\KitProbe;
use App\Services\OrderService;

/**
 * Regression coverage for the two v1.1.2 deployment leftovers:
 *  1. the kit builder emitted an unusable SW1 cert_config
 *     (domain_name instead of domain, no dns_provider / dns_env /
 *     email) — the first TRADE kits had to be repaired by hand;
 *  2. delivery verification (and the builder's own verification loop)
 *     probed once, immediately, so ordinary agent-sync lag was
 *     misjudged as failure. Both now retry with bounded backoff, and
 *     persistent failure still fails closed.
 *  3. health verification (verifyKit) had the same single-probe race
 *     on the probe identity's group change (v1.3.7 acceptance): the
 *     probe section now retries on the same doctrine — the retry trace
 *     may therefore come from either the builder layer
 *     (kit.verify.retry) or the health layer (health.verify.retry) —
 *     and only an exhausted run records a health failure.
 */
class EnterpriseVerifyRetryTest extends EnterpriseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'suzu_enterprise.delivery_verify_backoff_seconds' => [0, 0],
            'suzu_enterprise.build_verify_backoff_seconds' => [0, 0, 0, 0],
            'suzu_enterprise.health_verify_backoff_seconds' => [0, 0, 0],
        ]);
    }

    private function purchaseAndPay($user, ?string $purposeCode = 'JP_TIKTOK'): Order
    {
        $order = OrderService::createFromRequest(
            $user,
            $this->enterprisePlan,
            Plan::PERIOD_MONTHLY,
            null,
            $purposeCode
        );
        (new OrderService($order))->paid('CB-' . $order->trade_no);
        return $order->fresh();
    }

    /** Probe whose through-tunnel check fails a budgeted number of times per kit. */
    private function bindFlakyProbe(array $failuresByKitId = [], int $globalFailures = 0): object
    {
        $state = new class extends \stdClass {
            public array $failuresByKitId = [];
            public int $globalFailures = 0;
        };
        $state->failuresByKitId = $failuresByKitId;
        $state->globalFailures = $globalFailures;
        $fake = new class($state) implements KitProbe {
            public function __construct(private object $state)
            {
            }
            public function tcpReachable(string $host, int $port): bool
            {
                return true;
            }
            public function probeThroughNode(EnterpriseInventoryKit $kit, int $serverId, User $probeUser): array
            {
                $fail = false;
                if (($this->state->failuresByKitId[$kit->id] ?? 0) > 0) {
                    $this->state->failuresByKitId[$kit->id]--;
                    $fail = true;
                } elseif ($this->state->globalFailures > 0) {
                    $this->state->globalFailures--;
                    $fail = true;
                }
                if ($fail) {
                    return ['ok' => false, 'exit_ip' => null, 'status' => 0, 'error' => 'agent not synced yet'];
                }
                $node = EnterpriseInventoryNode::where('kit_id', $kit->id)->where('server_id', $serverId)->first();
                $rule = $node ? EnterpriseInventoryRule::where('kit_id', $kit->id)->where('node_role', $node->node_role)->first() : null;
                return ['ok' => true, 'exit_ip' => $rule?->target_ip, 'status' => 204, 'error' => null];
            }
        };
        $this->app->instance(KitProbe::class, $fake);
        return $state;
    }

    public function test_sw1_builder_writes_production_shaped_cert_config(): void
    {
        // A pre-existing production node carrying the shared credential set.
        $this->makePlainServer([$this->ordinaryGroup->id], [
            'name' => '生产SW1参考节点',
            'cert_config' => [
                'cert_mode' => 'dns',
                'domain' => 'prod.example.test',
                'dns_provider' => 'cloudflare',
                'dns_env' => ['CLOUDFLARE_DNS_API_TOKEN' => 'test-token-not-a-secret'],
                'email' => 'ops@example.com',
            ],
        ]);

        $sw1 = EnterprisePurpose::where('code', 'HK_TRADE')->firstOrFail();
        $sw1->machine_id = $this->machine->id;
        $sw1->entry_provider = EnterprisePurpose::PROVIDER_GOST;
        $sw1->entry_host = 'gost-hk.example.test';
        $sw1->dns_alias_suffix = 'ent.suzu.life';
        $sw1->save();

        $kit = $this->buildReadyKit($sw1);
        $this->assertCount(2, $kit->nodes);
        foreach ($kit->nodes as $node) {
            $cert = $node->server->cert_config;
            $this->assertIsArray($cert);
            $this->assertArrayNotHasKey('domain_name', $cert, 'the broken field name must never be emitted again');
            $this->assertSame('dns', $cert['cert_mode']);
            $this->assertSame('ent-hk-trade-001.ent.suzu.life', $cert['domain'], 'domain is the kit’s own alias');
            $this->assertSame('cloudflare', $cert['dns_provider']);
            $this->assertSame(['CLOUDFLARE_DNS_API_TOKEN' => 'test-token-not-a-secret'], $cert['dns_env']);
            $this->assertSame('ops@example.com', $cert['email']);
        }

        // Reality path untouched: no cert_config at all.
        $realityKit = $this->buildReadyKit();
        foreach ($realityKit->nodes as $node) {
            $this->assertNull($node->server->cert_config);
        }
    }

    public function test_build_verify_retries_failed_attempts_until_ready(): void
    {
        // First two through-tunnel probes fail (agent-sync lag), then healthy.
        $this->bindFlakyProbe(globalFailures: 2);

        $kit = $this->buildReadyKit();

        $this->assertSame(EnterpriseInventoryKit::STATUS_READY, $kit->fresh()->status);
        $this->assertTrue(
            EnterpriseInventoryLog::where('kit_id', $kit->id)
                ->whereIn('event', ['kit.verify.retry', 'health.verify.retry'])
                ->exists(),
            'the failed first attempt must be retried, with a log trace'
        );
    }

    public function test_delivery_verify_retries_then_marks_delivered(): void
    {
        $kit = $this->buildReadyKit();
        $this->registerRulePair(); // replenishment stock
        // The assigned kit's first delivery attempt fails on both nodes.
        $this->bindFlakyProbe(failuresByKitId: [$kit->id => 2]);

        $user = $this->makeUser();
        $order = $this->purchaseAndPay($user);

        $intent = EnterpriseOrderIntent::where('order_id', $order->id)->first();
        $this->assertNotNull($intent);
        $this->assertSame(EnterpriseOrderIntent::STATUS_DELIVERED, $intent->status);
        $this->assertTrue(
            EnterpriseInventoryLog::where('kit_id', $kit->id)->where('event', 'delivery.verify.retry')->exists(),
            'the lagging first attempt must leave a retry trace'
        );
    }

    public function test_health_verify_retries_until_agent_sync_catches_up(): void
    {
        $kit = $this->buildReadyKit();
        // First full attempt: both nodes fail (the agent has not pulled
        // the probe identity into the kit group yet); the bounded retry
        // rides out the lag and the kit verifies healthy.
        $this->bindFlakyProbe(failuresByKitId: [$kit->id => 2]);
        $health = app(EnterpriseInventoryHealthService::class);
        // A distinctive home group makes restoration observable.
        $probeUser = $health->probeUser();
        $probeUser->group_id = $this->ordinaryGroup->id;
        $probeUser->save();

        $result = $health->verifyKit($kit->fresh(), 'full');

        $this->assertTrue($result['ok']);
        $this->assertSame(
            0,
            (int) $kit->fresh()->health_fail_count,
            'a lagging first attempt must not count as a health failure'
        );
        $this->assertSame(EnterpriseInventoryKit::STATUS_READY, $kit->fresh()->status);
        $this->assertTrue(
            EnterpriseInventoryLog::where('kit_id', $kit->id)->where('event', 'health.verify.retry')->exists(),
            'the lagging attempt must leave a retry trace'
        );
        $this->assertSame(
            $this->ordinaryGroup->id,
            (int) $probeUser->fresh()->group_id,
            'probe user must be restored to its original group after verification'
        );
    }

    public function test_health_verify_persistent_failure_counts_once_after_exhaustion(): void
    {
        $kit = $this->buildReadyKit();
        $health = app(EnterpriseInventoryHealthService::class);
        $probeUser = $health->probeUser();
        $probeUser->group_id = $this->ordinaryGroup->id;
        $probeUser->save();
        $this->probe->healthy = false; // every probe fails, for the whole window

        $result = $health->verifyKit($kit->fresh(), 'full');

        $this->assertFalse($result['ok']);
        $this->assertSame(
            1,
            (int) $kit->fresh()->health_fail_count,
            'one verification run counts exactly one health failure, however many attempts it made'
        );
        $this->assertGreaterThanOrEqual(
            2,
            EnterpriseInventoryLog::where('kit_id', $kit->id)->where('event', 'health.verify.retry')->count(),
            'all bounded attempts must be exhausted (and traced) before failing'
        );
        $this->assertSame(
            1,
            EnterpriseInventoryLog::where('kit_id', $kit->id)->where('event', 'health.full.fail')->count(),
            'the failure must be recorded once, after exhaustion — not per attempt'
        );
        $this->assertSame(
            EnterpriseInventoryKit::STATUS_READY,
            $kit->fresh()->status,
            'a single failed run must not quarantine the kit'
        );
        $this->assertSame(
            $this->ordinaryGroup->id,
            (int) $probeUser->fresh()->group_id,
            'probe user must be restored to its original group even after exhaustion'
        );
    }

    public function test_delivery_verify_persistent_failure_alerts_and_never_delivers(): void
    {
        $kit = $this->buildReadyKit();
        $this->registerRulePair();
        $this->probe->healthy = false; // every probe fails, for the whole window

        $user = $this->makeUser();
        $order = $this->purchaseAndPay($user);

        $intent = EnterpriseOrderIntent::where('order_id', $order->id)->first();
        $this->assertNotNull($intent);
        $this->assertSame(
            EnterpriseOrderIntent::STATUS_RESERVED,
            $intent->status,
            'persistent failure must stay fail-closed: never DELIVERED'
        );
        $this->assertTrue(
            EnterpriseInventoryAlert::where('kit_id', $kit->id)
                ->where('alert_type', EnterpriseInventoryAlert::TYPE_DELIVERY_VERIFY_FAILED)
                ->exists()
        );
        $this->assertGreaterThanOrEqual(
            2,
            EnterpriseInventoryLog::where('kit_id', $kit->id)->where('event', 'delivery.verify.retry')->count(),
            'all bounded attempts must be exhausted (and traced) before failing'
        );
    }
}
