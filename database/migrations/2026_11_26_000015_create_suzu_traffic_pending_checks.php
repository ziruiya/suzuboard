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
 * MySQL 5.7 self-check: plain columns + one unique key — no 5.7
 * concerns. Idempotent guard mirrors the batch-1/2 migrations.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('suzu_traffic_pending_checks')) {
            return;
        }
        Schema::create('suzu_traffic_pending_checks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->unique();
            $table->bigInteger('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suzu_traffic_pending_checks');
    }
};
