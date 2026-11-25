<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SB-RELAY addressing fix + machine roles (2026-11-25):
 *
 *  - v2_server_machine.role: 'landing' | 'relay' — a machine is one or the
 *    other, never both (owner ruling 2026-11-25). Backfill: machines that
 *    already run the relay and host no nodes become 'relay'; every other
 *    machine stays 'landing' (its relay_enabled flag is inert from the
 *    moment the new panel gates on role).
 *  - v2_server_machine.public_address: the machine's authoritative public
 *    landing address (IP literal or hostname). The panel previously held
 *    NO machine address at all, which is half of the NODE-addressing
 *    defect: NODE relay targets are derived from the target node's
 *    machine public_address, never from the node's subscription host.
 *  - v2_server.direct_port: node-level override of the landing-reachable
 *    port for front-proxied nodes (e.g. nginx on the landing machine
 *    listening on 8444 in front of a loopback-only server_port 19444).
 *    Effective landing port = direct_port ?? server_port.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->string('role', 16)->default('landing')->after('is_active');
            $table->string('public_address', 255)->nullable()->after('role');
        });

        Schema::table('v2_server', function (Blueprint $table) {
            $table->unsignedInteger('direct_port')->nullable()->after('server_port');
        });

        DB::statement(
            "UPDATE v2_server_machine SET role = 'relay'
             WHERE relay_enabled = 1
               AND NOT EXISTS (SELECT 1 FROM v2_server WHERE v2_server.machine_id = v2_server_machine.id)"
        );
    }

    public function down(): void
    {
        Schema::table('v2_server', function (Blueprint $table) {
            $table->dropColumn(['direct_port']);
        });
        Schema::table('v2_server_machine', function (Blueprint $table) {
            $table->dropColumn(['role', 'public_address']);
        });
    }
};
