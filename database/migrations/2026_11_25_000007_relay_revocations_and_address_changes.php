<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GPT incremental review round 2 (SB-RELAY-01, B-batch):
 *
 * B01 — revocation obligations outlive the rule row. Round 1 tracked a
 * pending revocation as a timestamp ON the rule row, captured from the
 * row's APPLIED status at publish time. Two write paths defeated it:
 * disabling a rule flips its status to DISABLED *before* publish runs
 * (so the capture query no longer sees it as APPLIED), and deleting a
 * rule removes the very row the marker would live on. The obligation —
 * "the agent was confirmed serving this rule and has not yet confirmed
 * it stopped" — is a fact about the machine + rule_code, not about the
 * rule row, so it gets its own table. Rows are created at the operation
 * site (disable / delete / status flip) AND at publish time from the
 * document diff (last ACKed document vs new document), and are cleared
 * per rule by an agent ACK whose document actually excludes the rule.
 *
 * B03 — address changes become durable, resumable operations. Round 1
 * accounted per follower in the audit log and the HTTP response only:
 * a failed follower had no persistent record to retry from. The change
 * row below carries the per-follower outcome (published version /
 * failure, attempts, ACK convergence) so the reconcile loop (or an
 * admin) can resume it until every follower's applied version matches
 * the version this change published for it.
 *
 * MySQL 5.7 constraints honoured: explicit short index names, no JSON
 * columns (per-follower state is a JSON text payload), no foreign keys.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('suzu_relay_revocations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('machine_id');
            $table->unsignedBigInteger('rule_id')->nullable(); // rule row id at capture time; the row may be deleted afterwards
            $table->string('rule_code', 32);
            $table->string('reason', 24);                      // disabled | deleted | broken | excluded
            $table->unsignedInteger('since_version')->default(0); // first published version that excludes the rule (0 = not yet published)
            $table->timestamp('created_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->unsignedInteger('confirmed_version')->nullable();
            $table->string('confirm_note', 255)->nullable();

            $table->index(['machine_id', 'confirmed_at'], 'rl_rev_open_idx');
            $table->index('rule_code', 'rl_rev_code_idx');
        });

        Schema::create('suzu_relay_address_changes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('machine_id');          // the LANDING machine whose public_address changed
            $table->string('address', 255)->nullable();        // the new address (null = cleared)
            $table->string('actor_type', 16)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('status', 16)->default('in_progress'); // in_progress | partial | awaiting_ack | complete
            $table->text('followers')->nullable();             // JSON: machine_id -> {status, version, error, attempts, updated_at}
            $table->text('precheck')->nullable();              // JSON: {probed: [ports], passed: [ports], failed: [ports]}
            $table->timestamps();
            $table->timestamp('completed_at')->nullable();

            $table->index('status', 'rl_ac_status_idx');
            $table->index('machine_id', 'rl_ac_machine_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suzu_relay_address_changes');
        Schema::dropIfExists('suzu_relay_revocations');
    }
};
