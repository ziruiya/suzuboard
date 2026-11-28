<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('log_entries', function (Blueprint $table) {
            $table->bigIncrements('entry_id');
            $table->string('provider', 64);
            $table->string('entry_key', 128)->unique();
            $table->string('hostname', 255)->nullable();
            $table->enum('kind', ['third_party_forward', 'self_relay', 'vx_forward', 'direct']);
            $table->enum('shared_flag', ['shared', 'dedicated', 'unknown'])->default('unknown');
            $table->integer('config_version')->default(1);
            $table->string('note', 255)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });

        Schema::create('log_entry_dns_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('log_entries', 'entry_id');
            $table->string('resolved_ip', 45);
            $table->enum('ip_family', ['v4', 'v6']);
            $table->string('resolver', 64);
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at')->index();
            $table->enum('source', ['probe', 'patrol', 'manual']);
            $table->unique(['entry_id', 'resolved_ip', 'resolver'], 'log_dns_entry_ip_resolver_unique');
        });

        Schema::create('log_entry_mappings', function (Blueprint $table) {
            $table->bigIncrements('mapping_id');
            $table->foreignId('entry_id')->constrained('log_entries', 'entry_id');
            $table->string('entry_ip', 45);
            $table->integer('ingress_port')->nullable();
            $table->string('relay_rule_id', 64)->nullable();
            $table->integer('backend_node_id');
            $table->integer('backend_port');
            $table->dateTime('valid_from');
            $table->dateTime('valid_to')->nullable();
            $table->enum('provenance', ['contract', 'provider_screenshot', 'provider_api', 'manual_check', 'patrol-detection']);
            $table->enum('mapping_confidence', ['VERIFIED_DIRECT', 'VERIFIED_RELAY', 'MAPPED_UNIQUE', 'AMBIGUOUS']);
            $table->string('created_by', 64);
            $table->dateTime('created_at');
            $table->unique(['entry_id', 'valid_from']);
            $table->index(['entry_id', 'valid_to']);
            $table->index(['backend_node_id', 'valid_from']);
        });

        Schema::create('log_probe_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entry_id')->constrained('log_entries', 'entry_id');
            $table->string('probe_point', 32);
            $table->dateTime('checked_at')->index();
            $table->dateTime('received_at');
            $table->string('target_ip', 45);
            $table->enum('dns_state', ['ok', 'changed', 'nxdomain', 'error']);
            $table->enum('tcp_state', ['ok', 'timeout', 'refused', 'error']);
            $table->enum('handshake_state', ['ok', 'fail', 'na']);
            $table->decimal('loss_pct', 5, 2)->nullable();
            $table->integer('rtt_ms')->nullable();
            $table->string('error_class', 64)->nullable();
            $table->index(['entry_id', 'checked_at']);
            $table->index(['probe_point', 'checked_at']);
            $table->unique(['entry_id', 'probe_point', 'checked_at']);
        });

        Schema::create('log_entry_events', function (Blueprint $table) {
            $table->string('incident_id', 32)->primary();
            $table->foreignId('entry_id')->constrained('log_entries', 'entry_id');
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            $table->dateTime('last_success_at')->nullable();
            $table->dateTime('first_degraded_at')->nullable();
            $table->dateTime('first_failed_at')->nullable();
            $table->string('error_class', 64);
            $table->text('provider_notice')->nullable();
            $table->enum('attribution_status', [
                'UNKNOWN', 'PROVIDER_ORIGIN', 'DNS_CHANGED', 'UPSTREAM_NETWORK_ANOMALY',
                'BACKEND_FAILURE', 'INSUFFICIENT_EVIDENCE',
            ])->default('UNKNOWN');
            $table->text('hypothesis')->nullable();
            $table->enum('confidence', ['low', 'med', 'high'])->nullable();
            $table->enum('status', ['open', 'monitoring', 'closed']);
            $table->index(['entry_id', 'opened_at']);
        });

        Schema::create('log_entry_daily', function (Blueprint $table) {
            $table->foreignId('entry_id')->constrained('log_entries', 'entry_id');
            $table->date('date_cst');
            $table->integer('probe_total');
            $table->integer('probe_ok');
            $table->integer('degraded_minutes');
            $table->integer('failed_minutes');
            $table->integer('distinct_ips_seen');
            $table->string('event_ids', 255)->nullable();
            $table->primary(['entry_id', 'date_cst']);
            $table->index('date_cst');
        });
    }

    public function down(): void
    {
        foreach (['log_entry_daily', 'log_entry_events', 'log_probe_results', 'log_entry_mappings',
            'log_entry_dns_history', 'log_entries'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
