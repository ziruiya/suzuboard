<?php

namespace Tests\Feature\Relay;

use App\Models\RelayRule;
use App\Models\ServerMachine;
use App\Services\Relay\RelayRuleService;
use App\Services\Relay\RelayTrafficService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;

class RelayTrafficReceiptTest extends RelayTestCase
{
    public static function ruleStates(): iterable
    {
        yield 'active' => ['active', true];
        yield 'recently deleted' => ['recent', true];
        yield 'expired deletion' => ['expired', false];
        yield 'never existed' => ['unknown', false];
    }

    #[DataProvider('ruleStates')]
    public function test_final_batch_respects_rule_lifecycle(string $state, bool $accepted): void
    {
        [$machine, $rule] = $this->makeRule();
        $deletedAt = null;
        if ($state === 'recent' || $state === 'expired') {
            if ($state === 'expired') {
                $this->travel(-(RelayTrafficService::DELETED_RULE_GRACE_SECONDS + 1))->seconds();
            }
            $deletedAt = now()->timestamp;
            app(RelayRuleService::class)->delete($rule);
            $this->assertDatabaseMissing('suzu_relay_rules', ['id' => $rule->id]);
            $this->travelBack();
        }

        $batch = $this->batch($state === 'unknown' ? 'RL999999' : $rule->rule_code);
        // A final sample can arrive after removal; include its collection window.
        if ($deletedAt !== null) {
            $batch['period_start'] = $deletedAt - 10;
            $batch['period_end'] = $deletedAt + 1;
        }
        $service = app(RelayTrafficService::class);
        $result = $service->ingest($machine, [$batch]);
        $this->assertSame((int) $accepted, $result['accepted']);
        if (!$accepted) {
            $this->assertSame(1, $result['rejected']);
            $this->assertSame('unknown_rule', $result['rejected_batches'][0]['reason']);
            $this->assertDatabaseCount('suzu_relay_traffic_batches', 0);
            $this->assertDatabaseCount('suzu_relay_rule_stats', 0);
            return;
        }

        $this->assertSame(0, $result['rejected']);
        $this->assertSame(1, $service->ingest($machine, [$batch])['duplicates']);
        $this->assertDatabaseCount('suzu_relay_traffic_batches', 1);
        $this->assertDatabaseHas('suzu_relay_traffic_batches', [
            'rule_id' => $rule->id, 'bytes_in' => 144, 'rule_deleted_at' => $deletedAt,
        ]);
        $this->assertSame(144, (int) DB::table('suzu_relay_rule_stats')->where('rule_id', $rule->id)->where('record_type', 'd')->sum('u'));
    }

    public function test_non_unique_write_failure_propagates_and_retry_books_batch(): void
    {
        [$machine, $rule] = $this->makeRule();
        $batch = $this->batch($rule->rule_code);
        $service = app(RelayTrafficService::class);
        DB::unprepared("CREATE TRIGGER fail_relay_write BEFORE INSERT ON suzu_relay_traffic_batches
            BEGIN SELECT RAISE(ABORT, 'simulated write failure'); END");

        try {
            $service->ingest($machine, [$batch]);
            $this->fail('Non-unique write failure was acknowledged');
        } catch (QueryException $e) {
            $this->assertStringContainsString('simulated write failure', $e->getMessage());
        }
        $this->assertDatabaseCount('suzu_relay_traffic_batches', 0);
        $this->assertDatabaseCount('suzu_relay_rule_stats', 0);
        DB::unprepared('DROP TRIGGER fail_relay_write');

        $this->assertSame(1, $service->ingest($machine, [$batch])['accepted']);
        $this->assertSame(1, $service->ingest($machine, [$batch])['duplicates']);
        $this->assertDatabaseCount('suzu_relay_traffic_batches', 1);
    }

    public function test_same_batch_conflicts_are_counted_without_changing_receipt_or_booking(): void
    {
        [$machine, $rule] = $this->makeRule();
        $batch = $this->batch($rule->rule_code) + ['metadata' => ['b' => 2, 'a' => 1]];
        $service = app(RelayTrafficService::class);

        $this->assertSame(1, $service->ingest($machine, [$batch])['accepted']);
        $stored = DB::table('suzu_relay_traffic_batches')->first();
        $reordered = array_reverse(array_replace($batch, ['metadata' => ['a' => 1, 'b' => 2]]), true);
        $this->assertSame(1, $service->ingest($machine, [$reordered])['duplicates']);
        $this->assertDatabaseCount('suzu_relay_traffic_conflicts', 0);

        Log::spy();
        $altered = array_replace($batch, ['bytes_in' => 999]);
        $this->assertSame(1, $service->ingest($machine, [$altered])['duplicates']);
        $conflict = DB::table('suzu_relay_traffic_conflicts')->first();
        $this->assertSame(1, $conflict->count);
        $this->assertSame($stored->payload_digest, $conflict->first_digest);
        $this->assertNotSame($conflict->first_digest, $conflict->conflicting_digest);
        $this->assertSame($stored->batch_id, $conflict->batch_id);
        $this->assertSame($machine->id, $conflict->machine_id);
        $this->assertSame($rule->id, $conflict->rule_id);
        $this->assertNotNull($conflict->created_at);

        $this->travel(3)->seconds();
        $this->assertSame(1, $service->ingest($machine, [$altered])['duplicates']);
        $this->assertDatabaseCount('suzu_relay_traffic_conflicts', 1);
        $this->assertDatabaseHas('suzu_relay_traffic_conflicts', ['id' => $conflict->id, 'count' => 2, 'updated_at' => now()]);
        $this->assertDatabaseCount('suzu_relay_traffic_batches', 1);
        $this->assertEquals($stored, DB::table('suzu_relay_traffic_batches')->first());
        $this->assertSame(144, (int) DB::table('suzu_relay_rule_stats')->where('record_type', 'd')->sum('u'));
        Log::shouldHaveReceived('warning')->with('relay_traffic_batch_conflict', \Mockery::subset([
            'batch_id' => $stored->batch_id, 'conflicting_digest' => $conflict->conflicting_digest,
        ]))->twice();
    }

    public function test_legacy_receipt_reconstructs_original_metering_digest(): void
    {
        [$machine, $rule] = $this->makeRule();
        $batch = $this->batch($rule->rule_code);
        $service = app(RelayTrafficService::class);
        $this->assertSame(1, $service->ingest($machine, [$batch])['accepted']);
        DB::table('suzu_relay_traffic_batches')->update(['payload_digest' => null]);

        $this->assertSame(1, $service->ingest($machine, [$batch + ['old_extension' => 'unrecorded']])['duplicates']);
        $this->assertDatabaseCount('suzu_relay_traffic_conflicts', 0);
        $this->assertSame(1, $service->ingest($machine, [array_replace($batch, ['connections' => 1])])['duplicates']);
        $this->assertDatabaseCount('suzu_relay_traffic_conflicts', 1);
        $this->assertDatabaseHas('suzu_relay_traffic_batches', ['payload_digest' => null, 'connections' => 0]);
    }

    /** @return array{ServerMachine, RelayRule} */
    private function makeRule(): array
    {
        $machine = $this->enableRelay($this->makeMachine('Accounting relay'));
        return [$machine, app(RelayRuleService::class)->create($machine->fresh(), $this->manualRule())];
    }

    private function batch(string $code): array
    {
        return [
            'rule_code' => $code, 'boot_id' => 'test-boot', 'seq' => 1,
            'period_start' => now()->timestamp - 60, 'period_end' => now()->timestamp,
            'bytes_in' => 144, 'bytes_out' => 0,
        ];
    }
}
