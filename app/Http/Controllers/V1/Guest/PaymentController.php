<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\DB;

class PaymentController extends Controller
{
    public function notify($method, $uuid, Request $request)
    {
        HookManager::call('payment.notify.before', [$method, $uuid, $request]);
        try {
            $paymentService = new PaymentService($method, null, $uuid);
            $verify = $paymentService->notify($request->input());
            if (!$verify) {
                HookManager::call('payment.notify.failed', [$method, $uuid, $request]);
                return $this->fail([422, 'verify error']);
            }
            HookManager::call('payment.notify.verified', $verify);
            if (!is_array($verify)) {
                // Plugins may answer with a plain acknowledgement string
                // (e.g. CoinPayments "IPN OK: pending"): nothing to handle.
                return $verify;
            }
            // SB-MONEY-015: identify the payment instance that sent
            // this callback so handle() can reconcile against the
            // checkout attempt that instance issued.
            $paymentId = optional(\App\Models\Payment::where('uuid', $uuid)->first())->id;
            if (!$this->handle($verify['trade_no'], $verify['callback_no'], $verify, $paymentId)) {
                return $this->fail([400, 'handle error']);
            }
            return (isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, 'fail']);
        }
    }

    private function handle($tradeNo, $callbackNo, array $verify = [], $paymentId = null)
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            return $this->fail([400202, 'order is not found']);
        }
        if ($order->status !== Order::STATUS_PENDING) {
            // SB-MONEY-015: a re-notification of the transaction that
            // already paid this order is an idempotent ack. A
            // DIFFERENT transaction number on an already-paid order
            // is a second real payment (the customer paid two
            // checkout attempts): record it durably for manual
            // reconciliation instead of acknowledging it into
            // oblivion with its transaction number recorded nowhere.
            if ($order->status !== Order::STATUS_CANCELLED
                && $callbackNo
                && (string) $order->callback_no !== (string) $callbackNo) {
                return $this->recordReceiptOnPaidOrder($order, $callbackNo, $verify);
            }
            // SB-MONEY-002: a verified successful callback for an order
            // that was already cancelled is real money received. Record
            // the receipt on the order before acknowledging, so the
            // payment stays traceable (re-open or refund is then an
            // admin decision) instead of being confirmed to the gateway
            // and silently lost with no trace in the books.
            if ($order->status === Order::STATUS_CANCELLED) {
                // SB-MONEY-002 (fix-up): acknowledge only when the
                // receipt is verifiably on the books. If it cannot be
                // recorded (persistence failure, or the report carries
                // no verifiable amount/currency), fail so the gateway
                // keeps retrying instead of the money vanishing.
                return $this->recordReceiptOnCancelledOrder($order, $callbackNo, $verify);
            }
            return true;
        }
        // SB-AUD-002/030: when the gateway's verified callback reports the
        // paid amount, it must reconcile with the order total (integer
        // cents, orders are CNY-denominated) before the order may
        // complete. A mismatch fails closed: the order stays PENDING and
        // a correct callback can still complete it later.
        if (isset($verify['paid_currency'])
            && strtoupper((string) $verify['paid_currency']) !== 'CNY') {
            Log::warning('payment notify currency mismatch', [
                'trade_no' => $order->trade_no,
                'paid_currency' => $verify['paid_currency'],
            ]);
            return false;
        }
        // SB-MONEY-001: checkout quoted the gateway (order total + the
        // handling fee persisted on the order) and the gateway charges
        // and reports that quote — reconcile against the quote, not the
        // bare total, or every fee-bearing payment is rejected.
        $expectedAmount = (int) $order->total_amount + (int) ($order->handling_amount ?? 0);
        $matchedAttemptId = null;
        if (isset($verify['paid_amount_cents'])
            && (int) $verify['paid_amount_cents'] !== $expectedAmount) {
            // SB-MONEY-015: the callback may belong to an earlier
            // checkout attempt — the customer paid the first
            // gateway's link after re-checking-out with a second
            // method, whose quote overwrote the order's fields.
            // Reconcile against the recorded attempts (preferring
            // the attempt issued by the payment instance that sent
            // this callback) before failing closed.
            $matchedAttempt = $this->matchPaymentAttempt($order, (int) $verify['paid_amount_cents'], $paymentId);
            if (!$matchedAttempt) {
                Log::warning('payment notify amount mismatch', [
                    'trade_no' => $order->trade_no,
                    'paid_amount_cents' => $verify['paid_amount_cents'],
                    'expected_amount_cents' => $expectedAmount,
                    'order_total_amount' => $order->total_amount,
                ]);
                return false;
            }
            $matchedAttemptId = $matchedAttempt->id;
        }
        $orderService = new OrderService($order);
        if (!$orderService->paid($callbackNo)) {
            return false;
        }
        // SB-MONEY-002 (race): the order was PENDING at the first read
        // but the cancellation committed before paid() took its lock, so
        // paid() no-oped and the receipt would still be lost.
        $freshOrder = Order::find($order->id);
        if ($freshOrder && $freshOrder->status === Order::STATUS_CANCELLED) {
            // Same acknowledgement rule as the cancelled path above:
            // propagate the recording result instead of forcing true.
            return $this->recordReceiptOnCancelledOrder($freshOrder, $callbackNo, $verify);
        }

        // SB-MONEY-015: stamp the attempt this payment came through,
        // so the books show which issued quote was actually paid.
        if ($matchedAttemptId) {
            \App\Models\OrderPaymentAttempt::whereKey($matchedAttemptId)
                ->update(['callback_no' => (string) $callbackNo]);
        }

        HookManager::call('payment.notify.success', $order);
        return true;
    }

    /**
     * SB-MONEY-015: find a recorded checkout attempt for this order
     * whose payable total equals the amount the gateway reports.
     * Attempts issued by the payment instance that sent this callback
     * are preferred; any attempt of the order is accepted as a
     * fallback (gateways do not always identify their instance
     * reliably, and the amount itself is the reconciled quantity).
     */
    private function matchPaymentAttempt(Order $order, int $paidAmountCents, $paymentId): ?\App\Models\OrderPaymentAttempt
    {
        $query = \App\Models\OrderPaymentAttempt::where('order_id', $order->id)
            ->where('payable_cents', $paidAmountCents)
            ->orderByDesc('id');
        if ($paymentId) {
            $own = (clone $query)->where('payment_id', $paymentId)->first();
            if ($own) {
                return $own;
            }
        }
        return $query->first();
    }

    /**
     * SB-MONEY-015: persist a verified receipt that arrived for an
     * order which is already paid (PROCESSING/COMPLETED) under a
     * different transaction number — a second real payment, typically
     * the customer paying two checkout attempts. The order itself is
     * untouched (its entitlement was granted by the first payment);
     * the receipt goes to suzu_order_receipts for manual
     * reconciliation (refund or credit is an admin decision).
     *
     * Acknowledgement rules mirror the cancelled-order path
     * (SB-MONEY-002): incomplete evidence (no amount/currency) fails
     * so the gateway keeps retrying; a definitive currency mismatch
     * is logged and acknowledged without recording; a recorded (or
     * already recorded) receipt is acknowledged.
     */
    private function recordReceiptOnPaidOrder(Order $order, $callbackNo, array $verify): bool
    {
        $missing = [];
        if (!isset($verify['paid_amount_cents'])) {
            $missing[] = 'paid_amount_cents';
        }
        if (!isset($verify['paid_currency'])) {
            $missing[] = 'paid_currency';
        }
        if ($missing) {
            Log::warning('payment notify for paid order: receipt evidence incomplete, receipt not recorded', [
                'trade_no' => $order->trade_no,
                'callback_no' => $callbackNo,
                'missing' => $missing,
            ]);
            return false;
        }
        if (strtoupper((string) $verify['paid_currency']) !== 'CNY') {
            Log::warning('payment notify for paid order: currency mismatch, receipt not recorded', [
                'trade_no' => $order->trade_no,
                'paid_currency' => $verify['paid_currency'],
            ]);
            return true;
        }
        try {
            if (\App\Models\OrderReceipt::where('callback_no', (string) $callbackNo)->exists()) {
                return true;
            }
            \App\Models\OrderReceipt::create([
                'order_id' => $order->id,
                'callback_no' => (string) $callbackNo,
                'paid_amount_cents' => (int) $verify['paid_amount_cents'],
                'paid_currency' => strtoupper((string) $verify['paid_currency']),
                'note' => 'second receipt on already-paid order; recorded for manual reconciliation',
            ]);
        } catch (\Exception $e) {
            if (\App\Models\OrderReceipt::where('callback_no', (string) $callbackNo)->exists()) {
                return true;
            }
            Log::error('payment notify for paid order: receipt could not be persisted', [
                'trade_no' => $order->trade_no,
                'callback_no' => $callbackNo,
                'exception' => $e->getMessage(),
            ]);
            return false;
        }
        Log::error('payment received for already-paid order; second receipt recorded for manual reconciliation', [
            'trade_no' => $order->trade_no,
            'callback_no' => $callbackNo,
            'paid_amount_cents' => $verify['paid_amount_cents'],
        ]);
        return true;
    }

    /**
     * SB-MONEY-002: persist a verified receipt that arrived after the
     * order was cancelled. The order itself stays CANCELLED (its balance
     * was already refunded at cancel time, so silently re-opening it
     * would double-grant); saving callback_no + paid_at makes the
     * receipt findable in the books for a manual re-open or refund.
     *
     * Return value = whether the gateway may be acknowledged:
     *  - true: the receipt is verifiably persisted, or the report is
     *    complete but definitively fails reconciliation (evidence is
     *    logged for manual handling; retrying the identical report
     *    could never succeed, so the gateway may stop).
     *  - false: the receipt could NOT be confirmed — the report lacks
     *    a verifiable amount or currency, or the write itself failed.
     *    Nothing is written as received, and handle() propagates the
     *    failure so the gateway keeps retrying instead of the payment
     *    being confirmed here and lost with no trace in the books.
     *  - false (SB-MONEY-002-C1): a DIFFERENT transaction arrives for
     *    an order that already has a recorded receipt. That is a
     *    second real payment, not a duplicate notification: the
     *    recorded receipt stays untouched, the conflict is logged for
     *    manual reconciliation, and the acknowledgement fails.
     *
     * SB-MONEY-002-C1-R1: the whole check-and-record sequence runs
     * inside a transaction that re-reads the order row under a row
     * lock (keyed by order id). Two callbacks for the same cancelled
     * order can be in flight simultaneously, each holding a snapshot
     * read before the other committed; deciding from the caller's
     * snapshot let the second callback pass the C1 conflict check
     * with its stale NULL and overwrite the first receipt. The
     * locked re-read is the only state the decision may use, so on
     * MySQL/InnoDB (SELECT ... FOR UPDATE) the losing callback waits
     * for the winner's commit and then sees its recorded receipt.
     */
    private function recordReceiptOnCancelledOrder(Order $order, $callbackNo, array $verify): bool
    {
        try {
            return DB::transaction(function () use ($order, $callbackNo, $verify) {
                $locked = Order::where('id', $order->id)->lockForUpdate()->first();
                if (!$locked) {
                    Log::error('payment notify for cancelled order: order row not found on locked re-read, receipt not recorded', [
                        'trade_no' => $order->trade_no,
                        'callback_no' => $callbackNo,
                    ]);
                    return false;
                }
                return $this->recordReceiptOnLockedCancelledOrder($locked, $callbackNo, $verify);
            });
        } catch (\Exception $e) {
            // Persistence is the acknowledgement criterion: if the
            // receipt is not in the database (e.g. the AUD-040 unique
            // index rejects the callback_no), the gateway must not be
            // told the payment was handled.
            Log::error('payment notify for cancelled order: receipt could not be persisted', [
                'trade_no' => $order->trade_no,
                'callback_no' => $callbackNo,
                'exception' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * The body of the cancelled-order receipt recording, executed by
     * recordReceiptOnCancelledOrder() inside a transaction on the
     * row-locked, freshly re-read order. Every decision below reads
     * $order (the locked row), never a caller-side snapshot.
     */
    private function recordReceiptOnLockedCancelledOrder(Order $order, $callbackNo, array $verify): bool
    {
        // The receipt must be provable: both the paid amount and the
        // currency have to be reported. A verified signature without
        // them proves a notification arrived, not that this amount of
        // money did — record nothing and fail the acknowledgement.
        $missing = [];
        if (!isset($verify['paid_amount_cents'])) {
            $missing[] = 'paid_amount_cents';
        }
        if (!isset($verify['paid_currency'])) {
            $missing[] = 'paid_currency';
        }
        if ($missing) {
            Log::warning('payment notify for cancelled order: receipt evidence incomplete, receipt not recorded', [
                'trade_no' => $order->trade_no,
                'callback_no' => $callbackNo,
                'missing' => $missing,
            ]);
            return false;
        }
        if (strtoupper((string) $verify['paid_currency']) !== 'CNY') {
            Log::warning('payment notify for cancelled order: currency mismatch, receipt not recorded', [
                'trade_no' => $order->trade_no,
                'paid_currency' => $verify['paid_currency'],
            ]);
            return true;
        }
        $expectedAmount = (int) $order->total_amount + (int) ($order->handling_amount ?? 0);
        if ((int) $verify['paid_amount_cents'] !== $expectedAmount) {
            Log::warning('payment notify for cancelled order: amount mismatch, receipt not recorded', [
                'trade_no' => $order->trade_no,
                'paid_amount_cents' => $verify['paid_amount_cents'],
                'expected_amount_cents' => $expectedAmount,
            ]);
            return true;
        }
        // SB-MONEY-002-C1: a second, DIFFERENT transaction arriving
        // for an order that already has a recorded receipt is not a
        // duplicate notification — it is another real payment the
        // books know nothing about. Folding it into the first receipt
        // (keeping the old callback_no and acknowledging success)
        // would lose it exactly like the original 002 defect. Log the
        // conflict for manual reconciliation and fail the
        // acknowledgement; the recorded receipt stays untouched. (The
        // same transaction re-notified takes the idempotent path
        // below and is acknowledged.)
        if ($order->callback_no && (string) $order->callback_no !== (string) $callbackNo) {
            Log::error('payment notify for cancelled order: conflicting callback_no, second receipt not recorded', [
                'trade_no' => $order->trade_no,
                'recorded_callback_no' => $order->callback_no,
                'callback_no' => $callbackNo,
                'paid_amount_cents' => $verify['paid_amount_cents'],
            ]);
            return false;
        }
        $order->callback_no = $order->callback_no ?: $callbackNo;
        $order->paid_at = $order->paid_at ?: time();
        if (!$order->save()) {
            // save() can fail without throwing (e.g. a model
            // listener veto): the receipt is not persisted, so
            // the gateway must not be told it was handled.
            Log::error('payment notify for cancelled order: receipt could not be persisted (save returned false)', [
                'trade_no' => $order->trade_no,
                'callback_no' => $callbackNo,
            ]);
            return false;
        }
        Log::warning('payment received for cancelled order; receipt recorded for manual handling (re-open or refund)', [
            'trade_no' => $order->trade_no,
            'callback_no' => $callbackNo,
        ]);
        return true;
    }
}
