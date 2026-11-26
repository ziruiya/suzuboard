<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SB-MONEY-015: bind gateway payments to the checkout attempt that
 * produced them, and keep a durable record of receipts that cannot
 * be applied to an order.
 *
 * Attempts: every checkout used to overwrite the order's payment_id /
 * handling_amount in place and re-issue the same trade_no to the
 * gateway, so a callback could only ever be reconciled against the
 * LATEST quote — a customer who paid the first gateway's link after
 * re-checking-out with a second method saw the real payment rejected
 * on amount, and two successful payments left the second one
 * acknowledged with its transaction number recorded nowhere. Each
 * checkout now snapshots its quote (payment, handling fee, payable
 * total) as an attempt row; callbacks reconcile against the attempt
 * issued by their own payment instance as well as the order's
 * current quote.
 *
 * Receipts: a verified callback carrying a DIFFERENT transaction
 * number for an order that is already paid is a second real payment.
 * It is written here (unique callback_no) for manual reconciliation
 * instead of being acknowledged into oblivion.
 *
 * MySQL 5.7 self-check: plain columns + short indexes — no 5.7
 * concerns. Idempotent guards mirror the batch-2/3 migrations.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('suzu_order_payment_attempts')) {
            Schema::create('suzu_order_payment_attempts', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('order_id')->index();
                $table->unsignedBigInteger('payment_id')->nullable();
                $table->bigInteger('handling_amount')->default(0);
                $table->bigInteger('payable_cents');
                $table->string('callback_no', 191)->nullable();
                $table->bigInteger('created_at')->nullable();
                $table->bigInteger('updated_at')->nullable();
            });
        }
        if (!Schema::hasTable('suzu_order_receipts')) {
            Schema::create('suzu_order_receipts', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('order_id')->index();
                $table->string('callback_no', 191)->unique();
                $table->bigInteger('paid_amount_cents')->nullable();
                $table->string('paid_currency', 8)->nullable();
                $table->string('note', 255)->nullable();
                $table->bigInteger('created_at')->nullable();
                $table->bigInteger('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('suzu_order_receipts');
        Schema::dropIfExists('suzu_order_payment_attempts');
    }
};
