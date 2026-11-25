<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\Plugin\HookManager;

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
            if (!$this->handle($verify['trade_no'], $verify['callback_no'], $verify)) {
                return $this->fail([400, 'handle error']);
            }
            return (isset($verify['custom_result']) ? $verify['custom_result'] : 'success');
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, 'fail']);
        }
    }

    private function handle($tradeNo, $callbackNo, array $verify = [])
    {
        $order = Order::where('trade_no', $tradeNo)->first();
        if (!$order) {
            return $this->fail([400202, 'order is not found']);
        }
        if ($order->status !== Order::STATUS_PENDING) {
            // SB-MONEY-002: a verified successful callback for an order
            // that was already cancelled is real money received. Record
            // the receipt on the order before acknowledging, so the
            // payment stays traceable (re-open or refund is then an
            // admin decision) instead of being confirmed to the gateway
            // and silently lost with no trace in the books.
            if ($order->status === Order::STATUS_CANCELLED) {
                $this->recordReceiptOnCancelledOrder($order, $callbackNo, $verify);
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
        if (isset($verify['paid_amount_cents'])
            && (int) $verify['paid_amount_cents'] !== $expectedAmount) {
            Log::warning('payment notify amount mismatch', [
                'trade_no' => $order->trade_no,
                'paid_amount_cents' => $verify['paid_amount_cents'],
                'expected_amount_cents' => $expectedAmount,
                'order_total_amount' => $order->total_amount,
            ]);
            return false;
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
            $this->recordReceiptOnCancelledOrder($freshOrder, $callbackNo, $verify);
            return true;
        }

        HookManager::call('payment.notify.success', $order);
        return true;
    }

    /**
     * SB-MONEY-002: persist a verified receipt that arrived after the
     * order was cancelled. The order itself stays CANCELLED (its balance
     * was already refunded at cancel time, so silently re-opening it
     * would double-grant); saving callback_no + paid_at makes the
     * receipt findable in the books for a manual re-open or refund.
     * Reports that fail the same reconciliation as the pending path are
     * logged, not persisted.
     */
    private function recordReceiptOnCancelledOrder(Order $order, $callbackNo, array $verify): void
    {
        if (isset($verify['paid_currency'])
            && strtoupper((string) $verify['paid_currency']) !== 'CNY') {
            Log::warning('payment notify for cancelled order: currency mismatch, receipt not recorded', [
                'trade_no' => $order->trade_no,
                'paid_currency' => $verify['paid_currency'],
            ]);
            return;
        }
        $expectedAmount = (int) $order->total_amount + (int) ($order->handling_amount ?? 0);
        if (isset($verify['paid_amount_cents'])
            && (int) $verify['paid_amount_cents'] !== $expectedAmount) {
            Log::warning('payment notify for cancelled order: amount mismatch, receipt not recorded', [
                'trade_no' => $order->trade_no,
                'paid_amount_cents' => $verify['paid_amount_cents'],
                'expected_amount_cents' => $expectedAmount,
            ]);
            return;
        }
        try {
            $order->callback_no = $order->callback_no ?: $callbackNo;
            $order->paid_at = $order->paid_at ?: time();
            $order->save();
        } catch (\Exception $e) {
            Log::error($e);
        }
        Log::warning('payment received for cancelled order; receipt recorded for manual handling (re-open or refund)', [
            'trade_no' => $order->trade_no,
            'callback_no' => $callbackNo,
        ]);
    }
}
