<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * SB-MONEY-016: a commission withdrawal request with its amount
 * frozen at request time. The user's spendable commission is
 * commission_balance minus the sum of their PENDING withdrawals;
 * settling (paid) deducts the frozen amount from the balance, and
 * rejecting releases the freeze.
 */
class CommissionWithdrawal extends Model
{
    protected $table = 'suzu_commission_withdrawals';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp'
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_PAID = 'paid';
    const STATUS_REJECTED = 'rejected';

    public static function frozenAmountForUser(int $userId): int
    {
        return (int) self::where('user_id', $userId)
            ->where('status', self::STATUS_PENDING)
            ->sum('amount_cents');
    }

    public static function availableCommission(User $user): int
    {
        return (int) $user->commission_balance - self::frozenAmountForUser((int) $user->id);
    }
}
