<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('suzu_relay_rule_tombstones', function (Blueprint $table) {
            $table->unsignedBigInteger('machine_id');
            $table->string('rule_code', 32);
            $table->unsignedBigInteger('rule_id');
            $table->unsignedInteger('deleted_at');
            $table->primary(['machine_id', 'rule_code']);
        });
        Schema::table('suzu_relay_traffic_batches', function (Blueprint $table) {
            $table->unsignedInteger('rule_deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('suzu_relay_traffic_batches', function (Blueprint $table) {
            $table->dropColumn('rule_deleted_at');
        });
        Schema::dropIfExists('suzu_relay_rule_tombstones');
    }
};
