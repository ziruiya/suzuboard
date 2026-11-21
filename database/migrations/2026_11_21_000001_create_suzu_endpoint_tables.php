<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SB-Endpoint-01 Phase 1: endpoint contract registry.
 *
 * suzu_endpoint_contracts  — immutable snapshots of what a client subscription
 *                            promises for one logical node. NEVER stores
 *                            private keys or plaintext credentials; the
 *                            Reality key is represented by its SHA-256
 *                            fingerprint only.
 * suzu_endpoint_bindings   — the mutable "where does the entry point now"
 *                            state for a contract (DNS record / origin).
 * suzu_endpoint_changes    — append-only audit trail for snapshots,
 *                            preflights, DNS cutovers and rollbacks.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('suzu_endpoint_contracts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('logical_node_id');
            $table->unsignedInteger('version')->default(1);
            $table->string('legacy_hostname', 255);
            $table->unsignedInteger('client_port');
            $table->unsignedInteger('server_port')->nullable();
            $table->string('protocol', 32);
            $table->string('transport', 32)->nullable();
            $table->string('flow', 64)->nullable();
            $table->string('sni', 255)->nullable();
            $table->string('reality_public_key_fingerprint', 64)->nullable();
            $table->string('short_id', 64)->nullable();
            $table->string('grpc_service_name', 128)->nullable();
            $table->string('ws_path', 255)->nullable();
            $table->string('auth_namespace', 32)->default('user-uuid');
            $table->string('contract_hash', 64);
            $table->string('status', 16)->default('active');
            $table->timestamps();

            $table->unique(['logical_node_id', 'version'], 'suzu_contracts_node_version_unique');
            $table->index('contract_hash');
            $table->index('status');
            // No FK: v2_server rows are legacy-managed and may be rebuilt.
        });

        Schema::create('suzu_endpoint_bindings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('contract_id')->unique();
            $table->string('entry_mode', 16)->default('dns'); // dns | vx | direct
            $table->string('dns_zone_id', 64)->nullable();
            $table->string('dns_record_id', 64)->nullable();
            $table->string('current_entry_ip', 45)->nullable();
            $table->unsignedBigInteger('origin_id')->nullable();        // v2_server_machine.id
            $table->unsignedBigInteger('standby_origin_id')->nullable(); // v2_server_machine.id
            $table->string('health_status', 16)->default('unknown'); // unknown|passed|degraded|failed|error
            $table->json('last_preflight')->nullable();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->index('health_status');
        });

        Schema::create('suzu_endpoint_changes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('contract_id');
            $table->string('action', 32); // snapshot|supersede|preflight|dns_cutover|dns_rollback
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->unsignedInteger('from_revision')->nullable();
            $table->unsignedInteger('to_revision')->nullable();
            $table->json('old_binding')->nullable();
            $table->json('new_binding')->nullable();
            $table->json('precheck_result')->nullable();
            $table->json('postcheck_result')->nullable();
            $table->string('rollback_status', 16)->default('none'); // none|rolled_back|failed
            $table->unsignedBigInteger('related_change_id')->nullable();
            $table->timestamps();

            $table->index('contract_id');
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suzu_endpoint_changes');
        Schema::dropIfExists('suzu_endpoint_bindings');
        Schema::dropIfExists('suzu_endpoint_contracts');
    }
};
