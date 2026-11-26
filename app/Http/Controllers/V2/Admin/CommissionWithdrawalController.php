<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommissionWithdrawal;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SB-MONEY-016: settlement side of commission withdrawals. A pending
 * withdrawal froze its amount at request time; settle() pays it out
 * (deducting exactly the frozen amount from the commission balance)
 * and reject() releases the freeze. Both transitions are one-shot
 * from the pending state, under row locks.
 */
class CommissionWithdrawalController extends Controller
{
    public function settle(Request $request)
    {
        $reference = (string) $request->input('reference', '');
        if ($reference === '') {
            return $this->fail([422, 'reference is required']);
        }
        try {
            $settled = DB::transaction(function () use ($reference) {
                $withdrawal = CommissionWithdrawal::where('reference', $reference)
                    ->lockForUpdate()
                    ->first();
                if (!$withdrawal) {
                    return 'not_found';
                }
                if ($withdrawal->status !== CommissionWithdrawal::STATUS_PENDING) {
                    return 'not_pending';
                }
                $user = User::lockForUpdate()->find($withdrawal->user_id);
                if (!$user) {
                    return 'user_missing';
                }
                if ((int) $user->commission_balance < (int) $withdrawal->amount_cents) {
                    // The freeze accounting guarantees this cannot
                    // happen unless the balance was edited outside
                    // the guarded paths — fail loudly rather than
                    // settling into a negative commission balance.
                    Log::error('commission withdrawal settle: balance below frozen amount', [
                        'reference' => $reference,
                        'user_id' => $user->id,
                        'commission_balance' => $user->commission_balance,
                        'amount_cents' => $withdrawal->amount_cents,
                    ]);
                    return 'insufficient';
                }
                $user->commission_balance = (int) $user->commission_balance - (int) $withdrawal->amount_cents;
                if (!$user->save()) {
                    throw new \RuntimeException('Failed to save user balance.');
                }
                $withdrawal->status = CommissionWithdrawal::STATUS_PAID;
                if (!$withdrawal->save()) {
                    throw new \RuntimeException('Failed to save withdrawal status.');
                }
                Log::info('commission withdrawal settled', [
                    'reference' => $reference,
                    'user_id' => $user->id,
                    'amount_cents' => $withdrawal->amount_cents,
                ]);
                return 'settled';
            });
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '结算失败']);
        }
        return match ($settled) {
            'settled' => $this->success(true),
            'not_found' => $this->fail([400202, '提现申请不存在']),
            'not_pending' => $this->fail([400, '该提现申请已处理']),
            'user_missing' => $this->fail([400202, '用户不存在']),
            default => $this->fail([500, '佣金余额不足以结算该提现']),
        };
    }

    public function reject(Request $request)
    {
        $reference = (string) $request->input('reference', '');
        if ($reference === '') {
            return $this->fail([422, 'reference is required']);
        }
        try {
            $result = DB::transaction(function () use ($reference) {
                $withdrawal = CommissionWithdrawal::where('reference', $reference)
                    ->lockForUpdate()
                    ->first();
                if (!$withdrawal) {
                    return 'not_found';
                }
                if ($withdrawal->status !== CommissionWithdrawal::STATUS_PENDING) {
                    return 'not_pending';
                }
                $withdrawal->status = CommissionWithdrawal::STATUS_REJECTED;
                if (!$withdrawal->save()) {
                    throw new \RuntimeException('Failed to save withdrawal status.');
                }
                Log::info('commission withdrawal rejected, freeze released', [
                    'reference' => $reference,
                    'user_id' => $withdrawal->user_id,
                    'amount_cents' => $withdrawal->amount_cents,
                ]);
                return 'rejected';
            });
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '驳回失败']);
        }
        return match ($result) {
            'rejected' => $this->success(true),
            'not_found' => $this->fail([400202, '提现申请不存在']),
            default => $this->fail([400, '该提现申请已处理']),
        };
    }
}
