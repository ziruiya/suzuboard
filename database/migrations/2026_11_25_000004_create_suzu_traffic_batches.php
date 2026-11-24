<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SB-AUD-022: exactly-once markers for node traffic batches.
 *
 * The UniProxy push payload carries no batch identifier, so a batch that
 * is delivered twice (agent retry after an ambiguous timeout, queue
 * redelivery, a stat job retrying after a partial failure) used to be
 * counted twice. UserService::trafficFetch now derives a content hash
 * per chunk and every processor (traffic_fetch / stat_user / stat_server)
 * claims the batch here inside the same transaction as its writes:
 * first claim wins, replays skip. Rows are pruned after 48 hours by the
 * daily reset:log command — realistic replays land within minutes.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('suzu_traffic_batches')) {
            Schema::create('suzu_traffic_batches', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->char('batch_hash', 64);
                $table->integer('server_id');
                $table->string('processor', 32);
                $table->integer('created_at');

                $table->unique(['batch_hash', 'processor'], 'suzu_tb_hash_processor_uq');
                $table->index('created_at', 'suzu_tb_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('suzu_traffic_batches');
    }
};
