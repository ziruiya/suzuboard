<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suzu_relay_traffic_batches', function (Blueprint $table) {
            $table->char('payload_digest', 64)->nullable();
        });

        Schema::create('suzu_relay_traffic_conflicts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('receipt_id');
            $table->unsignedBigInteger('machine_id');
            $table->unsignedBigInteger('rule_id');
            $table->string('batch_id', 160);
            $table->char('first_digest', 64);
            $table->char('conflicting_digest', 64);
            $table->unsignedBigInteger('count')->default(1);
            $table->timestamps();

            $table->unique(['receipt_id', 'conflicting_digest'], 'relay_traffic_conflict_identity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suzu_relay_traffic_conflicts');
        Schema::table('suzu_relay_traffic_batches', function (Blueprint $table) {
            $table->dropColumn('payload_digest');
        });
    }
};
