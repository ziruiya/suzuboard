<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Business plans batch 3 — 「业务套餐-独享整机」 (dedicated-VPS plan).
 *
 * Manual-fulfilment tier next to the inventory-backed 「独享端口」 plan:
 * the buyer picks ONE region at checkout; after payment an admin buys the
 * VPS, backfills its IP, the panel pre-registers the machine (name format
 * 「{邮箱前缀}用户VPS {ip}」), shows a pinned-version install command, and
 * once the agent checks in the panel provisions a dedicated group plus
 * exactly three dedicated lines (Reality / SS1 / SW1) on that machine and
 * grants the shared VIP lines. SLA: delivery target 3h from payment with
 * 90/150/180-minute escalations; on timeout the customer chooses between
 * waiting for the original region or a free switch to another region —
 * there is NO refund path anywhere in this flow (D08).
 *
 * MySQL 5.7 constraints honoured (same discipline as batches 1–2): explicit
 * short index names, no JSON columns, no foreign keys.
 */
return new class extends Migration {
    public function up(): void
    {
        // Tier markers so the two business plans are distinguishable from
        // ordinary plans everywhere (checkout, member center, rotation).
        Schema::table('v2_plan', function (Blueprint $table) {
            $table->string('business_tier', 16)->nullable();          // port | machine
            $table->boolean('machine_region_required')->default(false);
        });
        Schema::table('v2_order', function (Blueprint $table) {
            $table->string('machine_region_code', 8)->nullable();     // immutable region snapshot
        });
        Schema::table('v2_user', function (Blueprint $table) {
            $table->unsignedBigInteger('machine_fulfillment_id')->nullable();
            $table->index('machine_fulfillment_id', 'user_mf_idx');
        });

        Schema::create('suzu_machine_fulfillments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('order_id')->unique('mf_order_unique');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('plan_id');
            $table->string('region_code', 8);                          // KR|JP|SG|US|DE|UK|TW|HK
            $table->string('region_name', 32);
            $table->string('status', 32)->default('WAITING_PAYMENT');
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('delivery_deadline_at')->nullable();
            $table->boolean('sla_overdue')->default(false);
            $table->unsignedTinyInteger('sla_stage')->default(0);      // highest SLA stage fired: 0|1|2|3
            $table->dateTime('overdue_notice_at')->nullable();
            $table->dateTime('last_progress_notice_at')->nullable();
            $table->string('user_choice', 24)->nullable();             // WAIT_ORIGINAL | SWITCH_REGION
            $table->dateTime('user_choice_at')->nullable();
            $table->dateTime('extended_deadline_at')->nullable();
            $table->boolean('termination_review')->default(false);
            // Vendor purchase backfill (admin):
            $table->string('public_ip', 45)->nullable();
            $table->string('vendor_instance_id', 128)->nullable();
            $table->string('vendor_name', 128)->nullable();
            $table->text('purchase_note')->nullable();
            $table->dateTime('resource_expires_at')->nullable();
            $table->dateTime('lease_alert_at')->nullable();
            // Provisioning result:
            $table->unsignedBigInteger('machine_id')->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->unsignedBigInteger('reality_server_id')->nullable();
            $table->unsignedBigInteger('ss1_server_id')->nullable();
            $table->unsignedBigInteger('sw1_server_id')->nullable();
            $table->dateTime('provisioned_at')->nullable();
            $table->text('verify_report')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            // Expiry / reclaim:
            $table->dateTime('grace_until')->nullable();
            $table->text('reclaim_checklist')->nullable();
            $table->dateTime('reclaimed_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->timestamps();

            $table->index('user_id', 'mf_user_idx');
            $table->index('status', 'mf_status_idx');
        });

        Schema::create('suzu_machine_fulfillment_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('fulfillment_id')->nullable();
            $table->string('actor_type', 8)->default('system');        // system | admin | user
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('event', 64);
            $table->text('detail')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['fulfillment_id', 'created_at'], 'mf_logs_fid_idx');
        });

        Schema::create('suzu_port_rotation_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id');
            $table->string('assignment_type', 16);                     // kit | machine
            $table->unsignedBigInteger('assignment_id')->nullable();
            $table->text('detail')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'created_at'], 'pr_logs_user_idx');
        });

        // ---- Tier marker for the existing inventory plan ----
        // Plan 6 (inventory tier) is the plan carrying the enterprise flag.
        // The dedicated-VPS plan row itself is created operationally at
        // deploy time (idempotent script, OFF-SALE), exactly like plan 6
        // was — seeding rows into v2_plan / v2_server_group here would
        // shift the early auto-increment ids other fixtures rely on.
        DB::table('v2_plan')
            ->where('enterprise_purpose_required', 1)
            ->whereNull('business_tier')
            ->update(['business_tier' => 'port']);
    }

    public function down(): void
    {
        Schema::dropIfExists('suzu_port_rotation_logs');
        Schema::dropIfExists('suzu_machine_fulfillment_logs');
        Schema::dropIfExists('suzu_machine_fulfillments');
        Schema::table('v2_user', function (Blueprint $table) {
            $table->dropColumn('machine_fulfillment_id');
        });
        Schema::table('v2_order', function (Blueprint $table) {
            $table->dropColumn('machine_region_code');
        });
        Schema::table('v2_plan', function (Blueprint $table) {
            $table->dropColumn(['business_tier', 'machine_region_required']);
        });
    }
};
