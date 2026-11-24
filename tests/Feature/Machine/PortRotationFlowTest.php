<?php

namespace Tests\Feature\Machine;

use App\Models\PortRotationLog;
use App\Models\RelayRule;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\Machine\PortRotationCooldownException;
use App\Services\Machine\PortRotationException;
use App\Services\Machine\PortRotationService;
use App\Services\Relay\RelayMachineService;
use App\Services\Relay\RelayRuleService;
use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Business plans batch 3 / D08: the shared 「更换端口」 button — rotation
 * moves entry + landing ports only (credentials, SNI, hosts and the
 * subscription address stay frozen), with a 2-hour per-user cooldown.
 */
class PortRotationFlowTest extends MachineTestCase
{
    private function rotation(): PortRotationService
    {
        return app(PortRotationService::class);
    }

    /** Direct machine nodes: ports rotate, everything else is frozen. */
    public function test_machine_direct_rotation_and_frozen_fields(): void
    {
        $user = $this->makeUser();
        $f = $this->deliverFor($user);
        $user = $user->fresh();

        $before = Server::whereIn('id', $f->nodeIds())->get()->keyBy('id');
        $frozen = [];
        foreach ($before as $id => $node) {
            $frozen[$id] = [
                'host' => $node->host,
                'uuid' => $user->uuid,
                'protocol_settings' => $node->protocol_settings,
                'group_ids' => $node->group_ids,
            ];
        }

        $results = $this->rotation()->rotateForUser($user);
        $this->assertCount(3, $results);

        $after = Server::whereIn('id', $f->nodeIds())->get()->keyBy('id');
        $newPorts = [];
        foreach ($after as $id => $node) {
            $this->assertNotSame((int) $before[$id]->server_port, (int) $node->server_port, 'landing port must change');
            $this->assertSame((string) $node->server_port, (string) $node->port, 'direct node: entry port follows landing port');
            $this->assertGreaterThanOrEqual(20000, (int) $node->server_port);
            $this->assertLessThanOrEqual(28999, (int) $node->server_port);
            $this->assertSame($frozen[$id]['host'], $node->host, 'host frozen');
            $this->assertSame($frozen[$id]['protocol_settings'], $node->protocol_settings, 'credentials/SNI frozen');
            $this->assertSame($frozen[$id]['group_ids'], $node->group_ids, 'group frozen');
            $newPorts[] = (int) $node->server_port;
        }
        $this->assertCount(3, array_unique($newPorts), 'rotated ports stay distinct on the machine');
        $this->assertSame($frozen[$before->keys()->first()]['uuid'], $user->fresh()->uuid, 'user credentials frozen');
        $this->assertSame(1, PortRotationLog::where('user_id', $user->id)->count());
    }

    /** Cooldown: a second rotation inside 2h is refused with remaining time; after 2h it works. */
    public function test_rotation_cooldown_two_hours(): void
    {
        $user = $this->makeUser();
        $this->deliverFor($user);
        $user = $user->fresh();

        $this->rotation()->rotateForUser($user);
        $this->assertGreaterThan(0, $this->rotation()->cooldownRemaining($user));

        try {
            $this->rotation()->rotateForUser($user);
            $this->fail('expected cooldown refusal');
        } catch (PortRotationCooldownException $e) {
            $this->assertGreaterThan(0, $e->remainingSeconds);
            $this->assertLessThanOrEqual(7200, $e->remainingSeconds);
        }

        Carbon::setTestNow(now()->addHours(2)->addMinute());
        try {
            $this->assertSame(0, $this->rotation()->cooldownRemaining($user->fresh()));
            $this->rotation()->rotateForUser($user->fresh());
            $this->assertSame(2, PortRotationLog::where('user_id', $user->id)->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    /** Relay-backed nodes: entry port re-claimed on the relay machine, landing port moved, config republished. */
    public function test_relay_backed_rotation(): void
    {
        $user = $this->makeUser();
        $f = $this->deliverFor($user);
        $user = $user->fresh();
        $node = Server::findOrFail($f->reality_server_id);

        // Put a relay machine + rule in front of the Reality node.
        $relayMachine = ServerMachine::create([
            'name' => '中转机', 'token' => Str::random(40), 'is_active' => true, 'relay_tier' => 'small',
        ]);
        app(RelayMachineService::class)->enable($relayMachine);
        $rule = app(RelayRuleService::class)->create($relayMachine->fresh(), [
            'name' => '整机-Reality 入口',
            'transport' => 'tcp',
            'listen_ip' => '0.0.0.0',
            'listen_port' => 20001,
            'target_type' => 'NODE',
            'target_node_id' => $node->id,
        ], 'admin', 1);
        $node->port = '20001';
        $node->save();
        $oldLanding = (int) $node->server_port;

        $results = $this->rotation()->rotateForUser($user);
        $relayResult = collect($results)->firstWhere('server_id', $node->id);
        $this->assertSame('relay', $relayResult['provider']);
        $this->assertNotSame(20001, $relayResult['entry_port']);
        $this->assertNotSame($oldLanding, $relayResult['new_port']);

        $rule = $rule->fresh();
        $this->assertSame($relayResult['entry_port'], (int) $rule->listen_port, 'relay listener re-claimed');
        $node = $node->fresh();
        $this->assertSame($relayResult['new_port'], (int) $node->server_port);
        $this->assertSame((string) $relayResult['entry_port'], (string) $node->port, 'client-facing entry follows the relay listener');
        $this->assertSame('203.0.113.10', $node->host, 'host frozen under relay rotation too');
    }

    /** A plain (non-business) account has nothing to rotate. */
    public function test_non_business_user_cannot_rotate(): void
    {
        $user = $this->makeUser(['group_id' => $this->plainGroup->id]);
        $status = $this->rotation()->statusForUser($user);
        $this->assertFalse($status['has_business']);
        $this->assertFalse($status['can_rotate']);
        try {
            $this->rotation()->rotateForUser($user);
            $this->fail('expected refusal');
        } catch (PortRotationException $e) {
            $this->assertTrue(true);
        }
    }

    /** Business status reflects the delivered machine tier. */
    public function test_status_for_machine_customer(): void
    {
        $user = $this->makeUser();
        $this->deliverFor($user);
        $status = $this->rotation()->statusForUser($user->fresh());
        $this->assertTrue($status['has_business']);
        $this->assertSame('machine', $status['tier']);
        $this->assertTrue($status['can_rotate']);
        $this->assertSame(0, $status['cooldown_seconds']);
    }
}
