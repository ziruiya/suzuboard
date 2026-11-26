<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SB-MONEY-015: a verified gateway receipt that could not be
 * applied to its order (a second, distinct transaction for an
 * already-paid order). Recorded durably for manual reconciliation
 * instead of being acknowledged to the gateway with no trace.
 */
class OrderReceipt extends Model
{
    protected $table = 'suzu_order_receipts';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];
}
