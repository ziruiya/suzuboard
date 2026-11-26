<?php

namespace Tests\Feature\Payment;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * SB-MONEY-005-IDX / M-P2-02: migration 000012 (commission_log level
 * column + UNIQUE(trade_no, level)) must be re-entrant in stages.
 * The pre-fix migration skipped everything once the level column
 * existed, so a run that died after adding the column but before
 * adding the unique index left the hardening silently unapplied
 * forever. Each test below stages a different starting state and
 * runs the migration's up() directly.
 */
class CommissionLogLevelMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const INDEX_NAME = 'v2_commission_log_trade_level_unique';

    protected function tearDown(): void
    {
        \Illuminate\Support\Facades\DB::purge();
        \Illuminate\Support\Facades\DB::disconnect();
        parent::tearDown();
    }

    private function runMigration(): void
    {
        $migration = require database_path(
            'migrations/2026_11_26_000012_add_level_to_v2_commission_log.php'
        );
        $migration->up();
    }

    /** Back to the pre-000012 shape: no unique index, no level column. */
    private function stripLevelSchema(): void
    {
        if ($this->hasTradeLevelUnique()) {
            Schema::table('v2_commission_log', function (Blueprint $table) {
                $table->dropUnique(self::INDEX_NAME);
            });
        }
        if (Schema::hasColumn('v2_commission_log', 'level')) {
            Schema::table('v2_commission_log', function (Blueprint $table) {
                $table->dropColumn('level');
            });
        }
    }

    private function addLevelColumnOnly(): void
    {
        Schema::table('v2_commission_log', function (Blueprint $table) {
            $table->unsignedTinyInteger('level')->nullable();
        });
    }

    private function insertRow(string $tradeNo, ?int $level = null): int
    {
        $data = [
            'invite_user_id' => 1,
            'user_id' => 2,
            'trade_no' => $tradeNo,
            'order_amount' => 2900,
            'get_amount' => 290,
            'created_at' => time(),
            'updated_at' => time(),
        ];
        if (Schema::hasColumn('v2_commission_log', 'level')) {
            $data['level'] = $level;
        }
        return (int) DB::table('v2_commission_log')->insertGetId($data);
    }

    private function hasTradeLevelUnique(): bool
    {
        return collect(Schema::getIndexes('v2_commission_log'))
            ->contains(fn($index) => ($index['name'] ?? null) === self::INDEX_NAME);
    }

    /** @return array<int, int|null> row id => level, in id order */
    private function levelsById(string $tradeNo): array
    {
        return DB::table('v2_commission_log')
            ->where('trade_no', $tradeNo)
            ->orderBy('id')
            ->pluck('level', 'id')
            ->map(fn($level) => $level === null ? null : (int) $level)
            ->all();
    }

    public function test_fresh_database_runs_to_completion(): void
    {
        $this->stripLevelSchema();
        $this->assertFalse(Schema::hasColumn('v2_commission_log', 'level'));

        $this->insertRow('T-FRESH-1');
        $this->insertRow('T-FRESH-1');
        $this->insertRow('T-FRESH-1');
        $this->insertRow('T-FRESH-2');

        $this->runMigration();

        $this->assertTrue(Schema::hasColumn('v2_commission_log', 'level'));
        $this->assertSame([0, 1, 2], array_values($this->levelsById('T-FRESH-1')));
        $this->assertSame([0], array_values($this->levelsById('T-FRESH-2')));
        $this->assertTrue($this->hasTradeLevelUnique());
    }

    public function test_half_finished_state_is_completed_on_rerun(): void
    {
        // The state the pre-fix migration stranded: column added by
        // a died-first-run, backfill and unique index never applied.
        $this->stripLevelSchema();
        $this->addLevelColumnOnly();
        $this->insertRow('T-HALF-1');
        $this->insertRow('T-HALF-1');
        $this->assertFalse($this->hasTradeLevelUnique());

        $this->runMigration();

        $this->assertSame([0, 1], array_values($this->levelsById('T-HALF-1')));
        $this->assertTrue($this->hasTradeLevelUnique());

        // And a further rerun is a harmless no-op (full re-entrancy).
        $this->runMigration();
        $this->assertSame([0, 1], array_values($this->levelsById('T-HALF-1')));
        $this->assertTrue($this->hasTradeLevelUnique());
    }

    public function test_existing_levels_are_never_overwritten(): void
    {
        // A partial backfill from the died run: the first row was
        // already levelled 1; the rest were never reached.
        $this->stripLevelSchema();
        $this->addLevelColumnOnly();
        $first = $this->insertRow('T-KEEP-1', 1);
        $second = $this->insertRow('T-KEEP-1');
        $third = $this->insertRow('T-KEEP-1');

        $this->runMigration();

        $levels = $this->levelsById('T-KEEP-1');
        // The recorded level survives verbatim; the unlevelled rows
        // take the remaining free levels in id order.
        $this->assertSame(1, $levels[$first]);
        $this->assertSame(0, $levels[$second]);
        $this->assertSame(2, $levels[$third]);
        $this->assertTrue($this->hasTradeLevelUnique());
    }
}
