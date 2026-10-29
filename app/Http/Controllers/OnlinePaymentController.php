<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\OnlinePayment;
use App\Models\Order;
use App\Services\PaymentGatewayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
    /**
     * v3.1.0 (M6) — admin/manager list of gateway payments (read-only).
     * Lists gateway, order, amount, status and gateway transaction id,
     * newest first, filterable by gateway and status.
     *
     * DELIBERATELY LIST-ONLY: there is no cancel/retry action here.
     * PaymentGatewayService has no safe cancel or re-initiate-from-row
     * method, and hand-marking a stale pending row 'failed' would be
     * UNSAFE, not just useless: handleCallback() below rejects
     * callbacks for non-pending, non-paid rows, so a late but genuine
     * gateway callback for that payment would then be refused and the
     * verified money would never reach the ledger. The safe action for
     * a stale pending row is to leave it pending and start a FRESH
     * payment from the order ledger (OrderController's initiate flow),
     * which creates a new OnlinePayment row with a new reference.
     * The gate mirrors AccountingController's requireManager() and the
     * route pattern (role:admin,manager), enforced here as well as in
     * routes, so the page stays protected even if routes are merged
     * into a different group.
     */
    public function index(Request $request)
    {
        abort_unless(auth()->check() && in_array(auth()->user()->role, ['admin', 'manager'], true), 403);

        $gateway = $request->input('gateway');
        $status = $request->input('status');

        $payments = OnlinePayment::with('order', 'user')
            ->when($gateway && in_array($gateway, ['jazzcash', 'easypaisa'], true), fn ($query) => $query->where('gateway', $gateway))
            ->when($status && in_array($status, ['pending', 'paid', 'failed'], true), fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('online-payments.index', [
            'payments' => $payments,
            'gateway' => $gateway,
            'status' => $status,
        ]);
    }

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

        // Idempotency pre-checks: already recorded — say so, record
        // nothing twice. These read fresh values but are NOT the
        // authority — the locked re-check inside the transaction
        // below is (v3.1.0 H6: the old code checked status outside
        // any lock, so two concurrent callbacks could both pass the
        // pending check and both write a ledger row).
        if ($payment->isPaid()) {
            return $ledgerRoute->with('status', 'Online payment was already recorded for this order.');
        }

        if (!$payment->isPending()) {
            return $ledgerRoute->withErrors(['error' => 'This online payment was already marked failed. Please start a new online payment from the ledger.']);
        }

        // THE gate: hash + gateway success status. v3.1.0 (M1): an
        // UNVERIFIED callback must NOT mark the pending row failed —
        // a transient gateway retry or a mis-signed retry could then
        // never succeed, and the row's history is lost. The payload is
        // logged server-side and stored on the row for inspection, the
        // row stays pending, and NO order payment is recorded. (The
        // amount-mismatch block below still marks failed: that callback
        // WAS verified, so retrying the same amount cannot help.)
        // The UBQARI-{id} reference format is deliberately UNCHANGED:
        // changing it would break gateway reconciliation.
        if (!$service->verifyCallback($gateway, $payload)) {
            Log::warning('Online payment callback failed hash verification; payment left pending.', [
                'gateway' => $gateway,
                'payment_id' => $payment->id,
                'payload' => $payload,
            ]);
            $payment->update(['callback_payload' => $payload]);


            return $ledgerRoute->withErrors(['error' => 'Online payment could not be verified with ' . ucfirst($gateway) . '. Nothing was recorded. Please retry the payment or start a new one.']);
        }

        $amount = $service->callbackAmount($gateway, $payload);

        // Amount missing, or different from what was initiated: do not
        // trust it as this order's payment.
        if ($amount === null || abs($amount - (float) $payment->amount) > 0.01) {
            OnlinePayment::where('id', $payment->id)->where('status', 'pending')
                ->update(['status' => 'failed', 'callback_payload' => $payload]);

            return $ledgerRoute->withErrors(['error' => 'Online payment amount did not match the initiated amount. Nothing was recorded.']);
        }

        $txnId = $service->callbackTransactionId($gateway, $payload);

        // v3.1.0 (H6): the WHOLE recording step runs in ONE
        // transaction — payment row locked, status re-checked on the
        // locked row, order row locked, due re-read on the locked
        // order — and only then is the row marked paid and the ledger
        // row written. A second concurrent callback for the same
        // payment serializes on the payment lock and hits the
        // already-paid re-check, recording nothing twice. The
        // overpayment guard (see class docblock) is re-evaluated here
        // on the locked due: verified money, but more than currently
        // due — keep the row 'paid' for audit and reconcile by hand
        // instead of corrupting the ledger.
        $outcome = DB::transaction(function () use ($payment, $amount, $gateway, $txnId, $payload) {
            $locked = OnlinePayment::lockForUpdate()->findOrFail($payment->id);

            if ($locked->isPaid()) {
                return ['kind' => 'already'];
            }
            if (!$locked->isPending()) {
                return ['kind' => 'rejected'];
            }

            $order = Order::lockForUpdate()->findOrFail($locked->order_id);

            if ($amount > (float) $order->due_amount) {
                $locked->update([
                    'status' => 'paid',
                    'gateway_txn_id' => $txnId,
                    'callback_payload' => $payload,
                ]);
                return ['kind' => 'overpaid', 'due' => (float) $order->due_amount];
            }

            $locked->update([
                'status' => 'paid',
                'gateway_txn_id' => $txnId,
                'callback_payload' => $payload,
            ]);

            // Mirror of OrderController::recordPayment() 'credit' branch.
            $order->payments()->create([
                'type' => 'credit',
                'amount' => $amount,
                'note' => 'Online payment via ' . ucfirst($gateway)
                    . ' (txn ' . $locked->gateway_txn_id . ')',
                'user_id' => $locked->user_id,
            ]);

            $order->decrement('due_amount', $amount);
            $order->increment('paid_amount', $amount);
            if ($order->customer_id && $order->status === 'completed') {
                // Locked when present; a trashed/missing customer keeps
                // the old silent no-op instead of failing the callback.
                Customer::lockForUpdate()->find($order->customer_id)?->decrement('credit_balance', $amount);
            }

            return ['kind' => 'recorded'];
        });

        if ($outcome['kind'] === 'already') {
            return $ledgerRoute->with('status', 'Online payment was already recorded for this order.');
        }
        if ($outcome['kind'] === 'rejected') {
            return $ledgerRoute->withErrors(['error' => 'This online payment was already marked failed. Please start a new online payment from the ledger.']);
        }
        if ($outcome['kind'] === 'overpaid') {
            return $ledgerRoute->withErrors(['error' => "Online payment of {$amount} via " . ucfirst($gateway) . " was verified, but it is more than the {$outcome['due']} still due on this order, so it was NOT added to the ledger. Please reconcile it manually."]);
        }

        return $ledgerRoute->with('status', "Online payment of {$amount} via " . ucfirst($gateway) . " recorded. Order balance updated.");
    }
}
