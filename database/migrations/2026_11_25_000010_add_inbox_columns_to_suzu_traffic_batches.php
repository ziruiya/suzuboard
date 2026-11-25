<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SB-TA-B01: durable traffic-batch inbox.
 *
 * Batch-2 (000008) gave identified batches an id-derived claim key and
 * a content fingerprint, but the claim rows carried no payload: the
 * panel acknowledged a report (HTTP 200) as soon as the three
 * accounting jobs were QUEUED, and if a job then failed permanently
 * (retries exhausted, queue lost) the batch was gone for good — the
 * agent had already discarded it on the strength of the 200.
 *
 * This migration turns suzu_traffic_batches into a durable inbox for
 * identified batches: a row with processor='inbox' stores the full
 * validated chunk payload, its content hash and a processing status
 * (received -> processed) at report time, synchronously, before any
 * job is dispatched. HTTP 200 then means "durably received", and the
 * suzu:traffic-batch-reconcile command can re-drive any batch whose
 * processors never completed. Per-processor claim rows keep
 * status/payload NULL — only inbox rows use the new columns.
 *
 * MySQL 5.7 self-check:
 *  - payload LONGTEXT: stored off-page, no row-size pressure; the
 *    table's fixed-width columns stay far below the 65535-byte row
 *    limit and no index covers the payload.
 *  - status VARCHAR(16): the new index (status, created_at) is at
 *    most 16*4 (utf8mb4) + 4 = 68 bytes, far below the 767-byte
 *    InnoDB index limit on 5.7.
 *  - processed_at INT: no index.
 *  - Identifiers: payload (7), status (6), processed_at (12),
 *    suzu_tb_status_created_idx (26) — all well under the 64-char
 *    identifier limit.
 * Every change is guarded per column/index so a partially applied
 * migration can be re-run safely.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('suzu_traffic_batches')) {
            return;
        }

        if (!Schema::hasColumn('suzu_traffic_batches', 'payload')) {
            Schema::table('suzu_traffic_batches', function (Blueprint $table) {
                $table->longText('payload')->nullable();
            });
        }
        if (!Schema::hasColumn('suzu_traffic_batches', 'status')) {
            Schema::table('suzu_traffic_batches', function (Blueprint $table) {
                $table->string('status', 16)->nullable();
            });
        }
        if (!Schema::hasColumn('suzu_traffic_batches', 'processed_at')) {
            Schema::table('suzu_traffic_batches', function (Blueprint $table) {
                $table->integer('processed_at')->nullable();
            });
        }

        $hasIndex = collect(Schema::getIndexes('suzu_traffic_batches'))
            ->contains(fn($index) => ($index['name'] ?? null) === 'suzu_tb_status_created_idx');
        if (!$hasIndex) {
            Schema::table('suzu_traffic_batches', function (Blueprint $table) {
                $table->index(['status', 'created_at'], 'suzu_tb_status_created_idx');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('suzu_traffic_batches')) {
            return;
        }

        $hasIndex = collect(Schema::getIndexes('suzu_traffic_batches'))
            ->contains(fn($index) => ($index['name'] ?? null) === 'suzu_tb_status_created_idx');
        if ($hasIndex) {
            Schema::table('suzu_traffic_batches', function (Blueprint $table) {
                $table->dropIndex('suzu_tb_status_created_idx');
            });
        }
        foreach (['payload', 'status', 'processed_at'] as $column) {
            if (Schema::hasColumn('suzu_traffic_batches', $column)) {
                Schema::table('suzu_traffic_batches', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
