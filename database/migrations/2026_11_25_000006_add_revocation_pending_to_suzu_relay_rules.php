<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GPT review R03 (SB-RELAY-01): revocation confirmation tracking.
 *
 * When a publish drops a rule the agent had previously ACKed as APPLIED,
 * the panel cannot know the agent actually stopped serving it until the
 * agent ACKs a document that no longer contains the rule. Until then the
 * removal is only panel-side intent. `revocation_pending_at` marks that
 * window explicitly: set at publish time for previously-APPLIED rules
 * excluded from the new document, cleared only by the agent's ACK of a
 * newer document. The reconcile loop reports the outstanding count, so
 * an offline agent leaves a visible "pending revocation" instead of a
 * silent assumption that the rule is gone.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('suzu_relay_rules', function (Blueprint $table) {
            $table->timestamp('revocation_pending_at')->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('suzu_relay_rules', function (Blueprint $table) {
            $table->dropColumn(['revocation_pending_at']);
        });
    }
};
