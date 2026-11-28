<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('log_probe_results', function (Blueprint $table) {
            $table->string('report_digest', 64)->nullable();
        });
        Schema::table('log_entry_events', function (Blueprint $table) {
            $table->dateTime('observed_at')->nullable();
            $table->dateTime('computed_at')->nullable();
            $table->boolean('historical_backfill')->default(false);
        });
        Schema::table('log_entry_mappings', function (Blueprint $table) {
            // A manual mapping may take effect at the exact second of a DNS observation.
            $table->unique(['entry_id', 'valid_from', 'provenance'], 'log_mapping_entry_time_provenance_unique');
            $table->dropUnique(['entry_id', 'valid_from']);
        });

        DB::table('log_entry_mappings')->where('provenance', 'patrol-detection')
            ->select('mapping_id', 'entry_id', 'valid_from')
            ->chunkById(100, function ($candidates) {
                foreach ($candidates as $candidate) {
                    $confirmed = DB::table('log_entry_mappings')->where('entry_id', $candidate->entry_id)
                        ->where('provenance', '!=', 'patrol-detection');
                    $next = (clone $confirmed)->where('valid_from', '>', $candidate->valid_from)->min('valid_from');
                    $confirmed->where('valid_to', $candidate->valid_from)->update(['valid_to' => $next]);
                    DB::table('log_entry_mappings')->where('mapping_id', $candidate->mapping_id)->update([
                        'valid_to' => $candidate->valid_from,
                        'mapping_confidence' => 'AMBIGUOUS',
                    ]);
                }
            }, 'mapping_id');
    }

    public function down(): void
    {
        Schema::table('log_entry_mappings', function (Blueprint $table) {
            $table->unique(['entry_id', 'valid_from']);
            $table->dropUnique('log_mapping_entry_time_provenance_unique');
        });
        Schema::table('log_entry_events', function (Blueprint $table) {
            $table->dropColumn(['observed_at', 'computed_at', 'historical_backfill']);
        });
        Schema::table('log_probe_results', function (Blueprint $table) {
            $table->dropColumn('report_digest');
        });
    }
};
