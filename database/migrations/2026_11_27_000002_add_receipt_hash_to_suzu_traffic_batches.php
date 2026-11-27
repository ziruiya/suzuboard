<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('suzu_traffic_batches', function (Blueprint $table) {
            $table->char('receipt_hash', 64)->nullable()->index();
        });
    }

    public function down(): void
    {
        DB::table('suzu_traffic_batches')->where('processor', 'batch_receipt')->delete();
        Schema::table('suzu_traffic_batches', function (Blueprint $table) {
            $table->dropIndex(['receipt_hash']);
            $table->dropColumn('receipt_hash');
        });
    }
};
