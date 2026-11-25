<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SB-TA-012/013: per-account authentication version.
 *
 * Every credential-revoking event (password reset by the user,
 * password change or ban by an admin) increments auth_version in the
 * same transaction that deletes the account's Sanctum tokens.
 * Pre-signed quick-login / mail-link tickets record the auth_version
 * (and, for quick-login tickets, the issuing Sanctum token id) at
 * issuance; redemption refuses tickets whose version no longer matches
 * or whose issuing session is gone, so a revoked session cannot be
 * rebuilt from a ticket issued before the revocation.
 *
 * MySQL 5.7 self-check: single INT column with a constant default, no
 * index, identifier lengths unchanged — no 5.7 concerns.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('v2_user') && !Schema::hasColumn('v2_user', 'auth_version')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->integer('auth_version')->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('v2_user') && Schema::hasColumn('v2_user', 'auth_version')) {
            Schema::table('v2_user', function (Blueprint $table) {
                $table->dropColumn('auth_version');
            });
        }
    }
};
