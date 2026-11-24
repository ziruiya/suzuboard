<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SB-AUD-036: the traffic stat tables' unique keys omitted record_type
 * even though every write path treats (…, record_at, record_type) as the
 * logical key (the MySQL upsert uniqueBy and the SQLite lookup both
 * include it). Day and month rows share the same record_at on the first
 * of a month, so a month row would be silently merged into the day row
 * by ON DUPLICATE KEY UPDATE. Production was checked before this
 * migration: all existing rows are record_type 'd' and there are no
 * duplicates on the extended key, so widening the keys cannot conflict.
 */
return new class extends Migration {
    public function up(): void
    {
        if ($this->hasIndex('v2_stat_user', 'server_rate_user_id_record_at')) {
            Schema::table('v2_stat_user', function (Blueprint $table) {
                $table->dropUnique('server_rate_user_id_record_at');
            });
        }
        if (!$this->hasIndex('v2_stat_user', 'stat_user_rate_user_at_type_uq')) {
            Schema::table('v2_stat_user', function (Blueprint $table) {
                $table->unique(
                    ['server_rate', 'user_id', 'record_at', 'record_type'],
                    'stat_user_rate_user_at_type_uq'
                );
            });
        }

        if ($this->hasIndex('v2_stat_server', 'server_id_server_type_record_at')) {
            Schema::table('v2_stat_server', function (Blueprint $table) {
                $table->dropUnique('server_id_server_type_record_at');
            });
        }
        if (!$this->hasIndex('v2_stat_server', 'stat_server_id_type_at_type_uq')) {
            Schema::table('v2_stat_server', function (Blueprint $table) {
                $table->unique(
                    ['server_id', 'server_type', 'record_at', 'record_type'],
                    'stat_server_id_type_at_type_uq'
                );
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('v2_stat_user', 'stat_user_rate_user_at_type_uq')) {
            Schema::table('v2_stat_user', function (Blueprint $table) {
                $table->dropUnique('stat_user_rate_user_at_type_uq');
            });
        }
        if (!$this->hasIndex('v2_stat_user', 'server_rate_user_id_record_at')) {
            Schema::table('v2_stat_user', function (Blueprint $table) {
                $table->unique(
                    ['server_rate', 'user_id', 'record_at'],
                    'server_rate_user_id_record_at'
                );
            });
        }

        if ($this->hasIndex('v2_stat_server', 'stat_server_id_type_at_type_uq')) {
            Schema::table('v2_stat_server', function (Blueprint $table) {
                $table->dropUnique('stat_server_id_type_at_type_uq');
            });
        }
        if (!$this->hasIndex('v2_stat_server', 'server_id_server_type_record_at')) {
            Schema::table('v2_stat_server', function (Blueprint $table) {
                $table->unique(
                    ['server_id', 'server_type', 'record_at'],
                    'server_id_server_type_record_at'
                );
            });
        }
    }

    private function hasIndex(string $table, string $name): bool
    {
        try {
            foreach (Schema::getIndexes($table) as $index) {
                if (($index['name'] ?? null) === $name) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            // Fall through: treat as absent; the guarded create below is
            // the operation that matters.
            return false;
        }
        return false;
    }
};
