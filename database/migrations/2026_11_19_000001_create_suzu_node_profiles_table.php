<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('suzu_node_profiles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('server_id')->unique();
            $table->string('profile_code', 8);
            $table->unsignedInteger('schema_version')->default(1);
            $table->string('relay_policy', 16)->default('required');
            $table->string('rollout_stage', 16)->default('draft');
            $table->string('client_policy', 32)->default('standard');
            $table->boolean('udp_enabled')->default(false);
            $table->timestamps();
            // No FK until legacy deletion behavior is confirmed on staging.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suzu_node_profiles');
    }
};
