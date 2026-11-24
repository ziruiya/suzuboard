<?php

namespace Tests\Feature\Enterprise;

use App\Models\EnterpriseInventoryAlert;
use App\Models\EnterpriseInventoryKit;
use App\Models\EnterpriseInventoryNode;
use App\Models\EnterpriseKitContractSnapshot;
use App\Models\EnterprisePurpose;
use App\Models\SuzuNodeProfile;
use App\Services\Enterprise\EnterpriseKitBuilderService;

/**
 * SB-ENT-INV-01 builder-level guarantees: AUD-015 group isolation on every
 * built node, same-machine rule pairs, contract snapshots, SW1 profile.
 */
class EnterpriseKitBuilderTest extends EnterpriseTestCase
{
    public function test_built_nodes_are_isolated_to_the_kit_group_only(): void
    {
        $kit = $this->buildReadyKit();
        $this->assertCount(2, $kit->nodes);
        foreach ($kit->nodes as $node) {
            $server = $node->server;
            $this->assertSame(
                [(string) $kit->stock_group_id],
                array_map('strval', $server->group_ids ?? []),
                'AUD-015: a kit node may belong to its own group ONLY'
            );
            $this->assertFalse((bool) $server->show, 'stock nodes stay hidden until assignment');
            $this->assertSame($node->dns_alias, $server->host);
            $this->assertSame((int) $node->port, (int) $server->port);
            $this->assertSame((int) $node->landing_port, (int) $server->server_port);
        }
        // Alias shape: ent-{code}-{seq}.{suffix}
        $this->assertSame('ent-jp-tiktok-001.ent.suzu.life', $kit->nodes->first()->dns_alias);
        // Contract snapshot v1 exists per node, uuid empty until assignment.
        $snapshots = EnterpriseKitContractSnapshot::where('kit_id', $kit->id)->where('contract_version', 1)->get();
        $this->assertCount(2, $snapshots);
        foreach ($snapshots as $snapshot) {
            $this->assertSame('', $snapshot->uuid);
            $this->assertNotEmpty($snapshot->public_key);
            $this->assertNotEmpty($snapshot->short_id);
        }
        // Primary sorts ahead of backup.
        $primary = $kit->nodes->firstWhere('node_role', EnterpriseInventoryNode::ROLE_PRIMARY);
        $backup = $kit->nodes->firstWhere('node_role', EnterpriseInventoryNode::ROLE_BACKUP);
        $this->assertLessThan($backup->server->sort, $primary->server->sort);
    }

    public function test_rule_pair_must_land_on_the_same_machine(): void
    {
        // Two READY rules with different target IPs must not form a kit.
        $this->registerRulePair($this->purpose, '203.0.113.10');
        $mismatch = \App\Models\EnterpriseInventoryRule::create([
            'purpose_id' => $this->purpose->id,
            'provider' => $this->purpose->entry_provider,
            'public_entry_port' => ++$this->rulePortSeq,
            'target_ip' => '203.0.113.99',
            'target_port' => ++$this->landingPortSeq,
            'status' => \App\Models\EnterpriseInventoryRule::STATUS_READY,
        ]);
        // Force the mismatched rule to be the second candidate: retire one
        // rule of the good pair so the first two READY rules differ.
        $good = \App\Models\EnterpriseInventoryRule::where('purpose_id', $this->purpose->id)
            ->where('status', \App\Models\EnterpriseInventoryRule::STATUS_READY)
            ->orderBy('id')
            ->first();
        $good->status = \App\Models\EnterpriseInventoryRule::STATUS_DISABLED;
        $good->save();

        $kit = app(EnterpriseKitBuilderService::class)->buildKit($this->purpose);
        $this->assertSame(EnterpriseInventoryKit::STATUS_BUILDING, $kit->status);
        $this->assertCount(0, $kit->nodes, 'a mismatched pair must not materialize nodes');
        $this->assertNotNull($mismatch);
    }

    public function test_attach_rules_completes_a_stalled_kit(): void
    {
        $builder = app(EnterpriseKitBuilderService::class);
        $kit = $builder->buildKit($this->purpose); // no rules registered yet
        $this->assertSame(EnterpriseInventoryKit::STATUS_BUILDING, $kit->status);

        $pair = $this->registerRulePair();
        $kit = $builder->attachRules($kit, [$pair[0]->id, $pair[1]->id]);
        $this->assertSame(EnterpriseInventoryKit::STATUS_READY, $kit->status);
        $this->assertCount(2, $kit->nodes);
    }

    public function test_sw1_purpose_builds_ws_nodes_with_production_profile(): void
    {
        $sw1 = EnterprisePurpose::where('code', 'HK_TRADE')->firstOrFail();
        $sw1->machine_id = $this->machine->id;
        $sw1->entry_provider = EnterprisePurpose::PROVIDER_GOST;
        $sw1->entry_host = 'gost-hk.example.test';
        $sw1->dns_alias_suffix = 'ent.suzu.life';
        $sw1->save();

        $kit = $this->buildReadyKit($sw1);
        foreach ($kit->nodes as $node) {
            $settings = $node->server->protocol_settings;
            $this->assertSame('ws', $settings['network']);
            $this->assertSame('/SuzuWebSocket', $settings['network_settings']['path']);
            $profile = SuzuNodeProfile::where('server_id', $node->server_id)->first();
            $this->assertNotNull($profile);
            $this->assertSame('sw1', $profile->profile_code);
            $this->assertSame('production', $profile->rollout_stage);
        }
    }

    public function test_incomplete_blueprint_alerts_and_refuses_to_build(): void
    {
        $purpose = EnterprisePurpose::where('code', 'JP_SHOPPING')->firstOrFail();
        $this->expectException(\RuntimeException::class);
        try {
            app(EnterpriseKitBuilderService::class)->buildKit($purpose);
        } finally {
            $this->assertTrue(
                EnterpriseInventoryAlert::where('purpose_id', $purpose->id)
                    ->where('alert_type', EnterpriseInventoryAlert::TYPE_BLUEPRINT_INCOMPLETE)
                    ->exists()
            );
        }
    }
}
