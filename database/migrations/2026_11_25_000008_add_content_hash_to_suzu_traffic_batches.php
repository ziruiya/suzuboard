<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SB-TA-001/002: identified traffic batches.
 *
 * Batches that carry a persistent agent-assigned batch id are claimed
 * under an id-derived key (server + batch id + chunk sequence) instead
 * of the content hash. The content hash is kept alongside the claim so
 * a replay of the same id with different content is rejected as a
 * conflict instead of being silently treated as the same batch (002)
 * or as a brand-new batch (001).
 *
 * MySQL 5.7 self-check: single nullable CHAR(64) column, no new index,
 * identifier lengths unchanged — no 5.7 index/row-size concerns.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('suzu_traffic_batches') && !Schema::hasColumn('suzu_traffic_batches', 'content_hash')) {
            Schema::table('suzu_traffic_batches', function (Blueprint $table) {
                $table->char('content_hash', 64)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('suzu_traffic_batches') && Schema::hasColumn('suzu_traffic_batches', 'content_hash')) {
            Schema::table('suzu_traffic_batches', function (Blueprint $table) {
                $table->dropColumn('content_hash');
            });
        }
    }
};
