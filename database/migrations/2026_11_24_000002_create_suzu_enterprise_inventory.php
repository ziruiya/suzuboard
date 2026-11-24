<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SB-ENT-INV-01 (batch 1): enterprise-plan inventory pre-provisioning.
 *
 * Kits of dedicated nodes are built and health-verified BEFORE purchase, so a
 * paying enterprise customer is assigned a READY kit inside the paid() chain
 * instead of waiting for a line to be built.
 *
 * MySQL 5.7 constraints honoured: no JSON columns, no generated columns, no
 * partial indexes in the new tables; uniqueness that MySQL cannot express is
 * enforced in the service layer. No foreign keys (legacy v2_* tables are
 * managed outside migrations and rows may be rebuilt).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('suzu_enterprise_purpose', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('code', 20)->unique();
            $table->string('name', 64);
            $table->integer('sort')->default(0);
            $table->boolean('is_active')->default(true);
            // Build blueprint (filled in by ops before kits can be built):
            $table->unsignedBigInteger('machine_id')->nullable();      // v2_server_machine.id of the landing machine
            $table->string('protocol', 32)->nullable();                // vless_reality | vless_tls_ws
            $table->string('entry_provider', 16)->nullable();          // csmzzmb | gost | vx
            $table->string('entry_host', 255)->nullable();             // entry address DNS aliases point at
            $table->unsignedInteger('landing_port_base')->default(21000);
            $table->unsignedInteger('entry_port_base')->default(11000);
            $table->string('dns_alias_suffix', 255)->nullable();       // e.g. ent.suzu.club
            $table->timestamps();
        });

        Schema::create('suzu_enterprise_inventory_config', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('purpose_id')->unique();
            $table->integer('target_level')->default(3);   // D03: fixed 3 READY kits per SKU
            $table->integer('current_level')->default(0);
            $table->integer('lead_days')->default(3);
            $table->timestamps();
        });

        Schema::create('suzu_enterprise_inventory_kits', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('purpose_id');
            $table->unsignedBigInteger('stock_group_id')->unique();    // v2_server_group.id owned by this kit
            $table->unsignedInteger('sequence')->default(0);           // per-purpose #001 numbering
            $table->string('status', 24)->default('BUILDING');
            $table->unsignedBigInteger('current_user_id')->nullable();
            $table->unsignedBigInteger('current_order_id')->nullable();
            $table->string('build_reason', 64)->nullable();
            $table->unsignedInteger('current_contract_version')->default(0);
            $table->unsignedInteger('health_fail_count')->default(0);
            $table->dateTime('last_probe_at')->nullable();
            $table->dateTime('last_full_probe_at')->nullable();
            $table->dateTime('verify_expires_at')->nullable();
            $table->unsignedBigInteger('held_order_id')->nullable();
            $table->dateTime('hold_expires_at')->nullable();
            $table->dateTime('assigned_at')->nullable();
            $table->dateTime('grace_until')->nullable();
            $table->timestamps();

            $table->index(['purpose_id', 'status']);
            $table->index('status');
            $table->index('current_user_id');
        });

        Schema::create('suzu_enterprise_inventory_nodes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('kit_id');
            $table->unsignedBigInteger('server_id')->nullable();       // v2_server.id once the node exists
            $table->string('node_role', 8);                            // primary | backup
            $table->unsignedInteger('port')->default(0);               // client-facing entry port
            $table->unsignedInteger('landing_port')->nullable();       // agent listen port on the landing machine
            $table->string('dns_alias', 255)->nullable();
            $table->timestamps();

            $table->unique(['kit_id', 'node_role']);
            $table->index('server_id');
        });

        Schema::create('suzu_enterprise_inventory_rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('purpose_id')->nullable();
            $table->unsignedBigInteger('kit_id')->nullable();
            $table->string('node_role', 8)->nullable();                // primary | backup once consumed
            $table->unsignedTinyInteger('rule_slot')->default(1);
            $table->unsignedInteger('public_entry_port');
            $table->string('target_ip', 45);
            $table->unsignedInteger('target_port');
            $table->string('provider', 16);                            // csmzzmb | gost | vx
            $table->string('provider_rule_ref', 255)->nullable();
            $table->string('status', 16)->default('READY');            // READY | IN_USE | RESERVED | DISABLED
            $table->timestamps();

            $table->unique(['provider', 'public_entry_port'], 'ent_rules_provider_port_unique');
            $table->index(['purpose_id', 'status']);
            $table->index('kit_id');
        });

        Schema::create('suzu_enterprise_inventory_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('kit_id')->nullable();
            $table->string('actor_type', 8)->default('system');        // system | admin | user
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event', 64);
            $table->text('detail')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['kit_id', 'created_at']);
        });

        Schema::create('suzu_enterprise_kit_contract_snapshots', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('kit_id');
            $table->unsignedBigInteger('server_id');
            $table->unsignedInteger('contract_version');
            $table->string('entry_address', 255);
            $table->unsignedInteger('entry_port');
            $table->string('protocol', 32);                            // vless_reality | vless_tls_ws
            $table->string('uuid', 64);
            $table->string('short_id', 64)->nullable();
            $table->string('sni', 255)->nullable();
            $table->text('public_key')->nullable();
            $table->string('ws_path', 255)->nullable();
            $table->string('service_name', 255)->nullable();
            $table->string('target_ip', 45);
            $table->unsignedInteger('target_port');
            $table->string('dns_alias', 255);
            $table->unsignedInteger('revision')->default(1);
            $table->dateTime('captured_at');
            $table->timestamps();

            $table->unique(['kit_id', 'server_id', 'contract_version'], 'ent_snap_kit_server_ver_unique');
            $table->index('kit_id');
        });

        Schema::create('suzu_enterprise_order_intents', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('order_id')->nullable()->unique();
            $table->unsignedBigInteger('purpose_id');
            $table->unsignedBigInteger('kit_id')->nullable();
            $table->string('status', 24)->default('HELD');             // HELD | RESERVED | PAID_WAITING_INVENTORY | DELIVERED | FAILED
            $table->dateTime('hold_expires_at')->nullable();
            $table->dateTime('waiting_since')->nullable();
            $table->dateTime('last_alert_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index('kit_id');
            $table->index('status');
        });

        Schema::create('suzu_enterprise_inventory_alerts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('purpose_id')->nullable();
            $table->unsignedBigInteger('kit_id')->nullable();
            $table->string('alert_type', 64);
            $table->text('message');
            $table->string('status', 8)->default('OPEN');              // OPEN | ACKED | RESOLVED
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::table('v2_plan', function (Blueprint $table) {
            $table->boolean('enterprise_purpose_required')->default(false);
        });
        Schema::table('v2_order', function (Blueprint $table) {
            $table->string('enterprise_purpose_code', 20)->nullable();
            $table->unsignedBigInteger('enterprise_kit_id')->nullable();
            $table->index('enterprise_kit_id');
        });
        Schema::table('v2_user', function (Blueprint $table) {
            $table->unsignedBigInteger('enterprise_kit_id')->nullable();
            $table->index('enterprise_kit_id');
        });

        // Seed the four purposes + their inventory targets (D03: 3 READY kits each).
        $purposes = [
            ['code' => 'JP_TIKTOK', 'name' => '日本-TikTok用', 'sort' => 1],
            ['code' => 'JP_SHOPPING', 'name' => '日本-购物拍卖', 'sort' => 2],
            ['code' => 'HK_TRADE', 'name' => '香港-外贸', 'sort' => 3],
            ['code' => 'HK_GENERAL', 'name' => '香港-通用', 'sort' => 4],
        ];
        $now = date('Y-m-d H:i:s');
        foreach ($purposes as $purpose) {
            $id = DB::table('suzu_enterprise_purpose')->insertGetId([
                'code' => $purpose['code'],
                'name' => $purpose['name'],
                'sort' => $purpose['sort'],
                'is_active' => 1,
                'protocol' => str_starts_with($purpose['code'], 'HK_TRADE') ? 'vless_tls_ws' : 'vless_reality',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('suzu_enterprise_inventory_config')->insert([
                'purpose_id' => $id,
                'target_level' => 3,
                'current_level' => 0,
                'lead_days' => 3,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('v2_user', function (Blueprint $table) {
            $table->dropColumn('enterprise_kit_id');
        });
        Schema::table('v2_order', function (Blueprint $table) {
            $table->dropColumn(['enterprise_purpose_code', 'enterprise_kit_id']);
        });
        Schema::table('v2_plan', function (Blueprint $table) {
            $table->dropColumn('enterprise_purpose_required');
        });
        Schema::dropIfExists('suzu_enterprise_inventory_alerts');
        Schema::dropIfExists('suzu_enterprise_order_intents');
        Schema::dropIfExists('suzu_enterprise_kit_contract_snapshots');
        Schema::dropIfExists('suzu_enterprise_inventory_logs');
        Schema::dropIfExists('suzu_enterprise_inventory_rules');
        Schema::dropIfExists('suzu_enterprise_inventory_nodes');
        Schema::dropIfExists('suzu_enterprise_inventory_kits');
        Schema::dropIfExists('suzu_enterprise_inventory_config');
        Schema::dropIfExists('suzu_enterprise_purpose');
    }
};
