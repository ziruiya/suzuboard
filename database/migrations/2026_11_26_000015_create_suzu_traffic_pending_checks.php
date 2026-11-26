<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SB-TA-006: durable over-quota check events.
 *
 * Previously TrafficFetchJob committed the quota increments and only
 * THEN wrote the affected user ids into the Redis set
 * 'traffic:pending_check' for CheckTrafficExceeded to consume. If
 * that Redis write failed (or the worker died between commit and
 * write), the signal was lost permanently: a job retry short-circuits
 * on the batch claim, so the sadd was never re-attempted, and users
 * who had just crossed their quota were never pushed off the nodes by
 * the proactive check.
 *
 * The event now rides the quota transaction itself: one row per
 * affected user (unique user_id — repeated batches simply refresh
 * the pending state) inserted in the same transaction as the
 * increments. The consumer reads and deletes rows chunk-wise; a
 * crash between processing and deletion only causes an idempotent
 * re-check on the next run.
 *
 * TA-006-R1 (GPT review of 021c98ad352c, the batch's only No-Go):
 * the row carries a monotonically increasing `version`. A repeated
 * booking for the same user atomically bumps the version inside
 * the producer transaction (upsert — two concurrent producers can
 * never mint the same version for two distinct bookings). The
 * consumer reads (user_id, version) pairs and deletes a row only
 * when its version is still the one it read; a row re-booked while
 * the check was running survives for the next round. Without this,
 * the consumer's unconditional delete-by-user_id could erase a
 * fresh event that insertOrIgnore had folded into the existing
 * row — the same lost-signal defect TA-006 exists to kill.
 *
 * This migration is edited in place rather than followed by a
 * 000016: 000015 has never run in production (batch 2 is not
 * deployed), so the chain stays single-step; the hasColumn branch
 * below additionally heals any stray database that already ran the
 * pre-R1 shape of this migration.
 *
 * MySQL 5.7 self-check: plain columns + one unique key — no 5.7
 * concerns. Idempotent guard mirrors the batch-1/2 migrations.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('suzu_traffic_pending_checks')) {
            Schema::create('suzu_traffic_pending_checks', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('user_id')->unique();
                $table->bigInteger('created_at')->nullable();
                $table->unsignedBigInteger('version')->default(1);
            });
            return;
        }
        if (!Schema::hasColumn('suzu_traffic_pending_checks', 'version')) {
            Schema::table('suzu_traffic_pending_checks', function (Blueprint $table) {
                $table->unsignedBigInteger('version')->default(1);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('suzu_traffic_pending_checks');
    }
};
