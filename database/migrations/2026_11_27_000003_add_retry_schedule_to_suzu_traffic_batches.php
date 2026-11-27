<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('suzu_traffic_batches', function (Blueprint $table) {
            $table->unsignedInteger('retry_at')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->index(['status', 'retry_at'], 'suzu_tb_status_retry_idx');
        });
    }

    public function down(): void
    {
        DB::table('suzu_traffic_batches')->where('status', 'unrecoverable')->update(['status' => 'received']);
        Schema::table('suzu_traffic_batches', function (Blueprint $table) {
            $table->dropIndex('suzu_tb_status_retry_idx');
            $table->dropColumn(['retry_at', 'retry_count']);
        });
    }
};
