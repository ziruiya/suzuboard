<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('suzu_endpoint_contracts', function (Blueprint $table) {
            $table->string('client_port_range', 11)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('suzu_endpoint_contracts', function (Blueprint $table) {
            $table->dropColumn('client_port_range');
        });
    }
};
