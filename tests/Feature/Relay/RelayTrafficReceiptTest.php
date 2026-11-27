<?php

namespace Tests\Feature\Relay;

use App\Models\RelayRule;
use App\Models\ServerMachine;
use App\Services\Relay\RelayRuleService;
use App\Services\Relay\RelayTrafficService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
