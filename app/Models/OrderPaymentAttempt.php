<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SB-MONEY-015: one row per checkout — the quote (payment method,
 * handling fee, payable total) the gateway was sent for that
 * attempt. Callbacks reconcile against the attempt issued by their
 * own payment instance, not only against the order's latest quote.
 */
class OrderPaymentAttempt extends Model
{
    protected $table = 'suzu_order_payment_attempts';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
}
