<?php

namespace App\Http\Controllers;

use App\Models\OnlinePayment;
use App\Services\PaymentGatewayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** FEATURE 9 — gateway callbacks for JazzCash + Easypaisa.
 *
 * SECURITY — READ BEFORE WIRING ROUTES:
 *   These actions MUST be reachable without a logged-in session: the
 *   gateway posts server-to-server (and, for JazzCash, returns the
 *   customer's browser) with no shop login. The coordinator must
 *   therefore place the callback routes OUTSIDE the 'auth' middleware
 *   group AND add them to the CSRF exemption in bootstrap/app.php
 *   (see routes-fragment.txt). The hash check below is the ONLY
 *   authentication a callback gets — that is by design, and it is why
 *   an unverified callback records nothing, ever.
 *
 * RECORDING RULE (mirrors OrderController::recordPayment() for a
 * COMPLETED order, exactly):
 *   On a VERIFIED success callback, in one transaction:
 *     - the OnlinePayment row becomes 'paid' with gateway_txn_id and
 *       the full callback payload stored;
 *     - one OrderPayment row is created (type 'credit', note naming
 *       the gateway and transaction id, user_id = whoever started
 *       the payment — NOT auth()->id(), there is no session here);
 *     - the order's due_amount goes down and paid_amount goes up by
 *       the callback amount;
 *     - the named customer's credit_balance goes down by the same
 *       amount, but ONLY when the order is COMPLETED and has a
 *       customer (recordPayment's own scope limit: for an
 *       in_progress order the due has not been added to
 *       credit_balance yet, so adjusting it here would double-count;
 *       walk-in orders have no customer, so nothing extra moves).
 *   What recordPayment does NOT do — and neither does this — : it
 *   does not touch the order's total, status, payment_method, stock,
 *   or any other order field.
 *   A 'debit' is never created here: an online payment is always a
 *   credit (money received).
 *
 * IDEMPOTENCY: a callback for an already-paid row redirects with a
 *   success message and records NOTHING a second time (gateways
 *   retry callbacks). A callback for a non-pending, non-paid row
 *   (already failed) is rejected — start a fresh payment instead.
 *
 * OVERPAYMENT GUARD (the one deliberate deviation from a blind copy
 * of recordPayment, which rejects credits above the due): initiate()
 * caps the amount at the order's due, but the due can shrink between
 * initiating and callback (e.g. a cash payment recorded meanwhile).
 * If the verified callback amount is MORE than the current due, the
 * OnlinePayment row is still marked 'paid' (the gateway money is
 * real and verified) but NO OrderPayment row is created — recording
 * it would drive due_amount negative and corrupt the customer
 * balance. The redirect message tells the shop to reconcile that
 * payment by hand. See NOTES.txt.
 */
class OnlinePaymentController extends Controller
{
    public function jazzcashCallback(Request $request, PaymentGatewayService $service)
    {
        return $this->handleCallback($request, 'jazzcash', $service);
    }

    public function easypaisaCallback(Request $request, PaymentGatewayService $service)
    {
        return $this->handleCallback($request, 'easypaisa', $service);
    }

    private function handleCallback(Request $request, string $gateway, PaymentGatewayService $service)
    {
        $payload = $request->all();
        $payment = $service->paymentFromCallbackRef($gateway, $payload);

        // No matching row: nothing to mark, nothing to record. There
        // is no order to redirect to, so fall back to the POS screen.
        if (!$payment) {
            return redirect()->route('pos.index')
                ->withErrors(['error' => 'Online payment callback could not be matched to any payment. Nothing was recorded.']);
        }

        $ledgerRoute = redirect()->route('orders.ledger', $payment->order);

        // Idempotency: already recorded — say so, record nothing twice.
        if ($payment->isPaid()) {
            return $ledgerRoute->with('status', 'Online payment was already recorded for this order.');
        }

        if (!$payment->isPending()) {
            return $ledgerRoute->withErrors(['error' => 'This online payment was already marked failed. Please start a new online payment from the ledger.']);
        }

        // THE gate: hash + gateway success status. Anything else and
        // we mark failed and record NO order payment, no exceptions.
        if (!$service->verifyCallback($gateway, $payload)) {
            $payment->update(['status' => 'failed', 'callback_payload' => $payload]);

            return $ledgerRoute->withErrors(['error' => 'Online payment could not be verified with ' . ucfirst($gateway) . '. Nothing was recorded.']);
        }

        $amount = $service->callbackAmount($gateway, $payload);

        // Amount missing, or different from what was initiated: do not
        // trust it as this order's payment.
        if ($amount === null || abs($amount - (float) $payment->amount) > 0.01) {
            $payment->update(['status' => 'failed', 'callback_payload' => $payload]);

            return $ledgerRoute->withErrors(['error' => 'Online payment amount did not match the initiated amount. Nothing was recorded.']);
        }

        $order = $payment->order;

        // Overpayment guard (see class docblock): verified money, but
        // more than currently due — keep the row 'paid' for audit and
        // reconcile by hand instead of corrupting the ledger.
        if ($amount > (float) $order->due_amount) {
            $payment->update([
                'status' => 'paid',
                'gateway_txn_id' => $service->callbackTransactionId($gateway, $payload),
                'callback_payload' => $payload,
            ]);

            return $ledgerRoute->withErrors(['error' => "Online payment of {$amount} via " . ucfirst($gateway) . " was verified, but it is more than the {$order->due_amount} still due on this order, so it was NOT added to the ledger. Please reconcile it manually."]);
        }

        DB::transaction(function () use ($payment, $order, $amount, $gateway, $service, $payload) {
            $payment->update([
                'status' => 'paid',
                'gateway_txn_id' => $service->callbackTransactionId($gateway, $payload),
                'callback_payload' => $payload,
            ]);

            // Mirror of OrderController::recordPayment() 'credit' branch.
            $order->payments()->create([
                'type' => 'credit',
                'amount' => $amount,
                'note' => 'Online payment via ' . ucfirst($gateway)
                    . ' (txn ' . $payment->gateway_txn_id . ')',
                'user_id' => $payment->user_id,
            ]);

            $order->decrement('due_amount', $amount);
            $order->increment('paid_amount', $amount);
            if ($order->customer_id && $order->status === 'completed') {
                $order->customer()->decrement('credit_balance', $amount);
            }
        });

        return $ledgerRoute->with('status', "Online payment of {$amount} via " . ucfirst($gateway) . " recorded. Order balance updated.");
    }
}
