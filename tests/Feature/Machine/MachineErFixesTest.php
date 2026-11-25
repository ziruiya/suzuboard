<?php

namespace Tests\Feature\Machine;

use App\Models\MachineFulfillment;
use App\Models\MachineFulfillmentLog;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\Enterprise\EnterpriseGroupMembershipService;
use Carbon\Carbon;

/**
 * SB-ER batch-3 regression coverage for the dedicated-machine tier:
 *  ER-002 restore paths must re-grant the dedicated group to the
 *         fulfilment's own three nodes, not only to the VIP set;
 *  ER-004 a region switch after provisioning tears the old machine
 *         down instead of silently keeping it;
 *  ER-004-R1 the switch is atomic: if the fulfilment's final save
 *         fails, the teardown rolls back with it (this file);
 *  ER-005 a repurchase during RECLAIM_PENDING starts a fresh
 *         fulfilment instead of being swallowed by the reclaim;
 *  ER-010 TLS nodes are provisioned with the fleet's DNS-01
 *         credential set, and delivery verification refuses to
 *         deliver a TLS node whose cert source is incomplete.
 */
class MachineErFixesTest extends MachineTestCase
{
    private function dedicatedNodes(MachineFulfillment $f): array
    {
        return Server::whereIn('id', $f->nodeIds())->get()->all();
    }

    private function groupOnNode(Server $node, int $groupId): bool
    {
        return in_array((string) $groupId, array_map('strval', $node->group_ids ?? []), true);
    }

    public function test_suspend_and_resume_restores_dedicated_node_grants(): void
    {
        $user = $this->makeUser();
        $f = $this->deliverFor($user);
        foreach ($this->dedicatedNodes($f) as $node) {
            $this->assertTrue($this->groupOnNode($node, (int) $f->group_id), 'delivered node carries the dedicated group');
        }

        $this->service()->suspend($f->fresh());
        foreach ($this->dedicatedNodes($f->fresh()) as $node) {
            $this->assertFalse($this->groupOnNode($node->fresh(), (int) $f->group_id), 'suspend strips the dedicated group from the nodes');
        }

        $this->service()->resume($f->fresh());
        $f = $f->fresh();
        $this->assertSame(MachineFulfillment::STATUS_DELIVERED, $f->status);
        foreach ($this->dedicatedNodes($f) as $node) {
            $this->assertTrue(
                $this->groupOnNode($node->fresh(), (int) $f->group_id),
                'ER-002: resume must restore the dedicated group on the fulfilment\'s own nodes'
            );
        }
    }

    public function test_grace_renewal_restores_dedicated_node_grants(): void
    {
        $user = $this->makeUser();
        $f = $this->deliverFor($user);

        $user->expired_at = time() - 60;
        $user->save();
        $this->service()->tick();
        $f = $f->fresh();
        $this->assertSame(MachineFulfillment::STATUS_GRACE_HELD, $f->status);
        foreach ($this->dedicatedNodes($f) as $node) {
            $this->assertFalse($this->groupOnNode($node->fresh(), (int) $f->group_id), 'grace strips the dedicated group');
        }

        $renewal = $this->buyMachinePlan($user->fresh(), null);
        $this->payOrder($renewal);

        $f = $f->fresh();
        $this->assertSame(MachineFulfillment::STATUS_DELIVERED, $f->status);
        foreach ($this->dedicatedNodes($f) as $node) {
            $this->assertTrue(
                $this->groupOnNode($node->fresh(), (int) $f->group_id),
                'ER-002: grace renewal must restore the dedicated group on the fulfilment\'s own nodes'
            );
        }
    }

    public function test_region_switch_after_provisioning_tears_down_old_machine(): void
    {
        $user = $this->makeUser();
        $order = $this->buyMachinePlan($user, 'JP');
        $this->payOrder($order);
        $f = MachineFulfillment::where('order_id', $order->id)->firstOrFail();
        $this->service()->startPurchase($f);
        $this->service()->backfillIp($f->fresh(), [
            'public_ip' => '203.0.113.10',
            'vendor_name' => 'TestVendor',
            'vendor_instance_id' => 'ins-old',
            'resource_expires_at' => now()->addMonth()->toDateTimeString(),
        ]);
        $f = $f->fresh();
        $oldMachine = ServerMachine::findOrFail($f->machine_id);
        $oldToken = $oldMachine->token;
        $oldNodeIds = $f->nodeIds();
        $this->assertCount(3, $oldNodeIds);

        $f->sla_overdue = true;
        $f->save();
        $this->service()->recordUserChoice($f->fresh(), $user, MachineFulfillment::CHOICE_SWITCH_REGION, 'SG');

        $f = $f->fresh();
        $this->assertSame('SG', $f->region_code);
        $this->assertSame(MachineFulfillment::STATUS_PAID_PENDING_PURCHASE, $f->status);
        $this->assertNull($f->machine_id, 'ER-004: the old machine pointer must be cleared');
        $this->assertNull($f->group_id);
        $this->assertNull($f->public_ip);
        $this->assertSame([], $f->nodeIds());

        $oldMachine = $oldMachine->fresh();
        $this->assertFalse((bool) $oldMachine->is_active, 'old machine deactivated');
        $this->assertNotSame($oldToken, $oldMachine->token, 'old machine token rotated');
        foreach (Server::whereIn('id', $oldNodeIds)->get() as $node) {
            $this->assertFalse((bool) $node->enabled, 'old node disabled');
            $this->assertFalse((bool) $node->show, 'old node hidden');
        }

        // The new region provisions completely fresh artefacts.
        $this->service()->startPurchase($f);
        $this->service()->backfillIp($f->fresh(), [
            'public_ip' => '203.0.113.99',
            'vendor_name' => 'TestVendor',
            'vendor_instance_id' => 'ins-new',
            'resource_expires_at' => now()->addMonth()->toDateTimeString(),
        ]);
        $f = $f->fresh();
        $this->assertNotNull($f->machine_id);
        $this->assertNotSame($oldMachine->id, (int) $f->machine_id);
        foreach ($this->dedicatedNodes($f) as $node) {
            $this->assertSame('203.0.113.99', $node->host, 'new nodes point at the new region machine');
        }
    }

    public function test_region_switch_rolls_back_teardown_when_final_save_fails(): void
    {
        $user = $this->makeUser();
        $order = $this->buyMachinePlan($user, 'JP');
        $this->payOrder($order);
        $f = MachineFulfillment::where('order_id', $order->id)->firstOrFail();
        $this->service()->startPurchase($f);
        $this->service()->backfillIp($f->fresh(), [
            'public_ip' => '203.0.113.10',
            'vendor_name' => 'TestVendor',
            'vendor_instance_id' => 'ins-old',
            'resource_expires_at' => now()->addMonth()->toDateTimeString(),
        ]);
        $f = $f->fresh();
        $oldMachine = ServerMachine::findOrFail($f->machine_id);
        $oldToken = $oldMachine->token;
        $oldNodeIds = $f->nodeIds();
        $this->assertCount(3, $oldNodeIds);
        $groupId = (int) $f->group_id;
        $statusBefore = $f->status;
        foreach ($this->dedicatedNodes($f) as $node) {
            $this->assertTrue($this->groupOnNode($node, $groupId), 'dedicated group on the nodes before the switch');
        }

        // Put the VIP grant in place (the same call deliver() makes):
        // the teardown revokes it, so a half-committed switch would
        // strip it even though the fulfilment never moved.
        app(EnterpriseGroupMembershipService::class)->addGroupToVipServers($groupId);
        $this->assertTrue($this->groupOnNode($this->vipServer->fresh(), $groupId), 'VIP grant in place before the switch');

        $f->sla_overdue = true;
        $f->save();

        // Veto the fulfilment's final save (Eloquent saving event):
        // the switch must fail as one unit and roll the teardown back.
        $targetId = (int) $f->id;
        MachineFulfillment::saving(function ($m) use ($targetId) {
            if ((int) $m->id === $targetId) {
                return false;
            }
        });
        $thrown = null;
        try {
            $this->service()->recordUserChoice($f->fresh(), $user, MachineFulfillment::CHOICE_SWITCH_REGION, 'SG');
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown, 'ER-004-R1: a failed final save must surface, not be swallowed');

        $oldMachine = $oldMachine->fresh();
        $this->assertSame($oldToken, $oldMachine->token, 'old machine token must not rotate on a rolled-back switch');
        $this->assertTrue((bool) $oldMachine->is_active, 'old machine must stay active');
        foreach (Server::whereIn('id', $oldNodeIds)->get() as $node) {
            $this->assertTrue((bool) $node->enabled, 'old node must stay enabled');
            $this->assertTrue((bool) $node->show, 'old node must stay visible');
            $this->assertTrue($this->groupOnNode($node, $groupId), 'dedicated group must stay on the old nodes');
        }
        $this->assertTrue($this->groupOnNode($this->vipServer->fresh(), $groupId), 'VIP grant must survive a rolled-back switch');

        $f = $f->fresh();
        $this->assertSame('JP', $f->region_code);
        $this->assertSame($statusBefore, $f->status);
        $this->assertSame((int) $oldMachine->id, (int) $f->machine_id, 'fulfilment must still point at the old machine');
        $this->assertSame($groupId, (int) $f->group_id);
        $this->assertSame($oldNodeIds, $f->nodeIds(), 'node pointers must be intact');
        $this->assertSame('203.0.113.10', $f->public_ip);
        $this->assertTrue((bool) $f->sla_overdue, 'still awaiting the customer choice');
        $this->assertNull($f->user_choice);
        $this->assertSame(
            0,
            MachineFulfillmentLog::where('fulfillment_id', $f->id)->where('event', 'fulfillment.region_teardown')->count(),
            'teardown audit row must roll back with the switch'
        );
    }

    public function test_repurchase_during_reclaim_pending_creates_fresh_fulfilment(): void
    {
        $user = $this->makeUser();
        $f = $this->deliverFor($user);

        $user->expired_at = time() - 60;
        $user->save();
        $this->service()->tick();
        Carbon::setTestNow(now()->addHours(49));
        $this->service()->tick();
        Carbon::setTestNow();
        $f = $f->fresh();
        $this->assertSame(MachineFulfillment::STATUS_RECLAIM_PENDING, $f->status);

        $order2 = $this->buyMachinePlan($user->fresh(), 'JP');
        $this->payOrder($order2);

        $new = MachineFulfillment::where('order_id', $order2->id)->first();
        $this->assertNotNull($new, 'ER-005: the repurchase must own a fulfilment record');
        $this->assertNotSame($f->id, $new->id, 'ER-005: the repurchase must not be folded into the reclaiming record');
        $this->assertSame(MachineFulfillment::STATUS_PAID_PENDING_PURCHASE, $new->status);
        $this->assertSame(MachineFulfillment::STATUS_RECLAIM_PENDING, $f->fresh()->status, 'the reclaim itself is untouched');
        $this->assertSame(2, MachineFulfillment::count());
    }

    public function test_provisioned_tls_nodes_clone_the_fleet_cert_credentials(): void
    {
        $user = $this->makeUser();
        $f = $this->deliverFor($user);
        $nodes = collect($this->dedicatedNodes($f))->keyBy('id');
        foreach ([$f->ss1_server_id, $f->sw1_server_id] as $serverId) {
            $cert = $nodes[$serverId]->cert_config;
            $this->assertIsArray($cert);
            $this->assertSame('cloudflare', $cert['dns_provider']);
            $this->assertSame(
                ['CLOUDFLARE_DNS_API_TOKEN' => 'test-token-not-a-secret'],
                $cert['dns_env'],
                'ER-010: the credential set must be cloned from the fleet reference, not omitted'
            );
            $this->assertSame('ops@example.com', $cert['email']);
        }
        $this->assertNull($nodes[$f->reality_server_id]->cert_config, 'Reality node carries no cert config');
    }

    public function test_delivery_verification_fails_when_a_tls_cert_source_is_incomplete(): void
    {
        $user = $this->makeUser();
        $order = $this->buyMachinePlan($user);
        $this->payOrder($order);
        $f = MachineFulfillment::where('order_id', $order->id)->firstOrFail();
        $this->service()->startPurchase($f);
        $this->service()->backfillIp($f->fresh(), [
            'public_ip' => '203.0.113.10',
            'vendor_name' => 'TestVendor',
            'vendor_instance_id' => 'ins-001',
            'resource_expires_at' => now()->addMonth()->toDateTimeString(),
        ]);
        $f = $f->fresh();

        // Ops strips the credential set from the SW1 node (the pre-fix
        // shape): TCP still answers, but no certificate can ever issue.
        $sw1 = Server::findOrFail($f->sw1_server_id);
        $cert = $sw1->cert_config;
        unset($cert['dns_env']);
        $sw1->cert_config = $cert;
        $sw1->save();

        $machine = ServerMachine::findOrFail($f->machine_id);
        $machine->last_seen_at = time();
        $machine->save();
        $this->service()->tick();

        $f = $f->fresh();
        $this->assertSame(MachineFulfillment::STATUS_VERIFY_FAILED, $f->status, 'ER-010: an incomplete cert source must block delivery');
        $report = json_decode((string) $f->verify_report, true);
        $this->assertSame(false, $report['cert_source_sw1']);
        $this->assertSame(true, $report['cert_source_ss1']);
    }
}
