<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SB-MONEY-016: commission withdrawal requests become structured,
 * freezable records. Previously a withdrawal was just a ticket: the
 * amount was never recorded, the commission was never frozen, so the
 * same commission could be requested twice, or transferred into the
 * spendable balance while a payout was pending, and the balance the
 * admin eventually paid against could have changed arbitrarily.
 *
 * One row per request: amount frozen at request time, unique
 * reference quoted on the ticket, explicit status lifecycle
 * (pending -> paid | rejected). The user's spendable commission is
 * commission_balance minus the sum of pending rows; settlement
 * (paid) deducts the frozen amount from commission_balance, and
 * rejection simply releases the freeze.
 *
 * MySQL 5.7 self-check: plain columns + two short indexes — no 5.7
 * concerns. Idempotent guard mirrors the batch-2/3 migrations.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('suzu_commission_withdrawals')) {
            return;
        }
        Schema::create('suzu_commission_withdrawals', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('user_id')->index();
            $table->string('reference', 64)->unique();
            $table->bigInteger('amount_cents');
            $table->string('method', 64);
            $table->string('account', 255);
            $table->unsignedBigInteger('ticket_id')->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->bigInteger('created_at')->nullable();
            $table->bigInteger('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suzu_commission_withdrawals');
    }
};
