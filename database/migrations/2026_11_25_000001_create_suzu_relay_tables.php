<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SB-RELAY-01 (panel side): Xboard-native relay (gost fused into the agent).
 *
 * Six new tables plus relay state columns on v2_server_machine. The design is
 * v0.2 as reviewed by GPT: config sync is its own relay.config.v1 channel
 * (Outbox + full pull + persistent applied ACK, panel reconciles every
 * 5 minutes; rollback = republish old content under a higher version);
 * traffic accounting is an immutable batch ledger first (a batch counts only
 * when its UNIQUE insert succeeds, so a replayed batch can never double-book)
 * and NEVER touches v2_user.u/d.
 *
 * MySQL 5.7 constraints honoured: every index/unique name is explicit and
 * short (the v1.1.1 lesson: auto-generated names over 64 chars abort the
 * migration on 5.7), no JSON columns in the new tables (text payloads are
 * stored as plain text), no foreign keys (legacy v2_* tables are managed
 * outside migrations).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->boolean('relay_enabled')->default(false);
            $table->string('relay_status', 16)->default('DISABLED'); // DISABLED | ENABLED | DRAINING
            $table->string('relay_tier', 8)->default('small');       // small | large (preflight classes)
            $table->unsignedInteger('relay_rules_limit')->nullable(); // admin override of the tier default
            $table->unsignedInteger('relay_tcp_limit')->nullable();
            $table->unsignedInteger('relay_udp_limit')->nullable();
            $table->unsignedInteger('relay_config_version')->default(0);
            $table->string('relay_config_hash', 64)->nullable();
            $table->unsignedInteger('relay_applied_version')->default(0);
            $table->string('relay_applied_hash', 64)->nullable();
            $table->text('relay_last_error')->nullable();
            $table->timestamp('relay_applied_at')->nullable();
            $table->timestamp('relay_status_changed_at')->nullable();
        });

        Schema::create('suzu_relay_rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('rule_code', 32)->nullable();              // RL000001…, assigned from the row id in the same transaction, never reused
            $table->unsignedBigInteger('machine_id');
            $table->string('name', 128);
            $table->string('transport', 8)->default('both');          // tcp | udp | both
            $table->string('listen_ip', 45)->default('0.0.0.0');
            $table->unsignedInteger('listen_port');
            $table->string('target_type', 8);                         // NODE | MANUAL
            $table->unsignedBigInteger('target_node_id')->nullable(); // v2_server.id when NODE
            $table->string('target_host', 255);                       // original text as entered / node host
            $table->unsignedInteger('target_port');
            $table->string('target_resolve_mode', 12)->default('auto');
            $table->string('target_contract_hash', 64)->nullable();   // NODE contract snapshot hash
            $table->string('target_snapshot_host', 255)->nullable();  // diagnostics only
            $table->unsignedInteger('target_snapshot_port')->nullable();
            $table->string('target_resolved_ip', 45)->nullable();     // diagnostics only
            $table->boolean('enabled')->default(true);
            $table->string('status', 16)->default('NOT_APPLIED');     // NOT_APPLIED|APPLYING|APPLIED|DRAINING|DISABLED|CONFLICT|SUSPECT|DOWN|BROKEN|UNKNOWN
            $table->string('health_status', 12)->default('unknown');
            $table->unsignedInteger('config_version')->default(0);
            $table->string('config_hash', 64)->nullable();
            $table->text('allowlist_cidrs')->nullable();              // JSON array of CIDRs (text for 5.7)
            $table->boolean('allowlist_approved')->default(false);
            $table->unsignedInteger('max_connections')->default(2000);
            $table->unsignedInteger('udp_max_sessions')->default(1024);
            $table->unsignedInteger('udp_idle_timeout_sec')->default(60);
            $table->unsignedInteger('drain_timeout_sec')->default(300);
            $table->unsignedInteger('buffer_bytes')->default(32768);
            $table->unsignedBigInteger('rate_in_bps')->nullable();
            $table->unsignedBigInteger('rate_out_bps')->nullable();
            $table->unsignedInteger('burst_bytes')->nullable();
            $table->unsignedInteger('version')->default(1);           // optimistic lock
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique('rule_code', 'rl_rules_code_unique');
            $table->index(['machine_id', 'status'], 'rl_rules_machine_status_idx');
            $table->index('target_node_id', 'rl_rules_node_idx');
        });

        Schema::create('suzu_relay_port_claims', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('machine_id');
            $table->unsignedBigInteger('rule_id');
            $table->string('transport', 4);                           // tcp | udp (BOTH expands to two rows)
            $table->string('bind_family', 4);                         // ipv4 | ipv6
            $table->string('bind_ip', 45);
            $table->unsignedInteger('listen_port');
            $table->timestamps();

            $table->unique(['machine_id', 'transport', 'bind_family', 'bind_ip', 'listen_port'], 'rl_claims_unique');
            $table->index('rule_id', 'rl_claims_rule_idx');
        });

        Schema::create('suzu_relay_traffic_batches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('batch_id', 80);                           // "<machine>:<rule>:<boot>:<seq>"
            $table->unsignedBigInteger('machine_id');
            $table->unsignedBigInteger('rule_id');
            $table->string('boot_id', 64);
            $table->unsignedBigInteger('seq');
            $table->unsignedInteger('period_start');                  // unix ts, UTC window start
            $table->unsignedInteger('period_end');                    // unix ts, UTC window end (exclusive)
            $table->unsignedBigInteger('bytes_in')->default(0);
            $table->unsignedBigInteger('bytes_out')->default(0);
            $table->unsignedInteger('connections')->default(0);
            $table->timestamp('created_at')->nullable();              // immutable ledger: no updated_at

            $table->unique('batch_id', 'rl_batch_id_unique');
            $table->unique(['machine_id', 'rule_id', 'boot_id', 'seq'], 'rl_batch_mrbs_unique');
            $table->index(['rule_id', 'period_start'], 'rl_batch_rule_period_idx');
        });

        Schema::create('suzu_relay_rule_stats', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('rule_id');
            $table->string('record_type', 2);                         // d | m (AUD-036: part of the unique key)
            $table->unsignedInteger('record_at');                     // UTC day/month boundary unix ts
            $table->unsignedBigInteger('u')->default(0);              // bytes_in (client -> target)
            $table->unsignedBigInteger('d')->default(0);              // bytes_out (target -> client)
            $table->timestamps();

            $table->unique(['rule_id', 'record_type', 'record_at'], 'rl_stats_unique');
        });

        Schema::create('suzu_relay_config_outbox', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('machine_id');
            $table->unsignedInteger('version');
            $table->string('config_hash', 64);
            $table->longText('payload');                              // full relay.config.v1 document
            $table->string('status', 12)->default('pending');         // pending | acked | superseded
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['machine_id', 'version'], 'rl_outbox_mv_unique');
            $table->index(['machine_id', 'status'], 'rl_outbox_machine_status_idx');
        });

        Schema::create('suzu_relay_audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('machine_id')->nullable();
            $table->unsignedBigInteger('rule_id')->nullable();
            $table->string('actor_type', 8)->default('admin');        // admin | agent | system
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 48);
            $table->text('detail')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['machine_id', 'created_at'], 'rl_audit_machine_idx');
            $table->index(['rule_id', 'created_at'], 'rl_audit_rule_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suzu_relay_audit_logs');
        Schema::dropIfExists('suzu_relay_config_outbox');
        Schema::dropIfExists('suzu_relay_rule_stats');
        Schema::dropIfExists('suzu_relay_traffic_batches');
        Schema::dropIfExists('suzu_relay_port_claims');
        Schema::dropIfExists('suzu_relay_rules');
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->dropColumn([
                'relay_enabled', 'relay_status', 'relay_tier',
                'relay_rules_limit', 'relay_tcp_limit', 'relay_udp_limit',
                'relay_config_version', 'relay_config_hash',
                'relay_applied_version', 'relay_applied_hash',
                'relay_last_error', 'relay_applied_at', 'relay_status_changed_at',
            ]);
        });
    }
};
