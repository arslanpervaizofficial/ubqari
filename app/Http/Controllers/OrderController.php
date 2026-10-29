<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Services\PaymentGatewayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    /** Same date-range/search filtering as index() — reused by destroyAll()
     *  so "Delete All" removes exactly what's currently showing on screen. */
    private function filteredQuery(Request $request)
    {
        $range = $request->input('range', 'month');

        $from = null;
        $to = null;
        if ($range === 'today') {
            $from = now()->startOfDay();
            $to = now()->endOfDay();
        } elseif ($range === 'week') {
            $from = now()->startOfWeek();
            $to = now()->endOfWeek();
        } elseif ($range === 'month') {
            $from = now()->startOfMonth();
            $to = now()->endOfMonth();
        } elseif ($range === 'custom') {
            $from = $request->filled('from') ? $request->date('from')->startOfDay() : null;
            $to = $request->filled('to') ? $request->date('to')->endOfDay() : null;
        }

        $query = Order::whereIn('status', ['completed', 'cancelled']);
        if ($from) $query->where('created_at', '>=', $from);
        if ($to) $query->where('created_at', '<=', $to);

        if ($request->filled('q')) {
            $q = $request->input('q');
            $query->where(function ($qq) use ($q) {
                $qq->where('order_number', 'like', "%{$q}%");
                if (is_numeric($q)) {
                    $qq->orWhere('id', (int) $q);
                }
            });
        }

        return [$query, $range, $from, $to];
    }

    /** Sales-order history: daily/weekly/monthly/custom-calendar date filter,
     *  plus a free-text search by order number or numeric order id. */
    public function index(Request $request)
    {
        [$query, $range, $from, $to] = $this->filteredQuery($request);
        $orders = $query->with('customer', 'cashier')->withCount('items')
            ->latest()->paginate(20)->withQueryString();

        return view('orders.index', compact('orders', 'range', 'from', 'to'));
    }

    /** Reopens a completed order for editing — reverses its stock/ledger
     *  effects and hands it to the POS billing screen as the active cart.
     *  Mirrors POSController::loadOrderForEdit so it works the same whether
     *  the cashier types the order number on the billing screen or an
     *  admin clicks "Edit" from this Orders list. */
    /** Backdate/correct an order's date & time (e.g. entering a sale that
     *  actually happened yesterday). Admin/manager only — same permission
     *  level as editing an order's items. Moves both created_at (drives the
     *  invoice date, the Orders list, and the customer ledger) and, if the
     *  order was already completed before, original_completed_at too (the
     *  field the monthly sales graphs group by), so reports for the new
     *  date/old date both stay correct. */
    public function changeDate(Request $request, Order $order)
    {
        if (!in_array($order->status, ['completed', 'cancelled'])) {
            return back()->withErrors(['error' => 'Only completed or cancelled orders can have their date changed.']);
        }

        $data = $request->validate([
            // v2.2 (F2): future dates are rejected — a completed order
            // must not be moved into a future month's revenue. Judged
            // in shop time (Asia/Karachi), not server time. NOTE: no
            // audit row (who/from/to/reason) is written yet — that
            // needs a new table and is deferred, see CHANGES-v2.2.txt.
            // Movement/payment dates still keep their real dates and
            // do not move with the order.
            'order_date' => ['required', 'date', 'before_or_equal:' . \Carbon\Carbon::now('Asia/Karachi')->toDateTimeString()],
        ]);

        $newDate = \Carbon\Carbon::parse($data['order_date']);

        $order->created_at = $newDate;
        if ($order->original_completed_at) {
            $order->original_completed_at = $newDate;
        }
        $order->save();

        return back()->with('status', "Order {$order->order_number} date changed to {$newDate->format('Y-m-d H:i')}.");
    }

    public function edit(Order $order)
    {
        if ($order->status !== 'completed') {
            return back()->withErrors(['error' => 'Only completed orders can be edited.']);
        }

        DB::transaction(function () use ($order) {
            // Hold/discard whatever the current cashier session was working on.
            Order::where('user_id', auth()->id())
                ->where('status', 'in_progress')
                ->get()
                ->each(function ($stray) {
                    // Empty cart: permanently discard, never send to Trash
                    // (same v1.4 rule POSController already uses).
                    $stray->items()->count() > 0 ? $stray->forceFill(['status' => 'hold'])->save() : $stray->forceDelete();
                });

            $order->reverseCompletedEffects();
            $order->forceFill(['status' => 'in_progress'])->save();
        });

        request()->session()->put('current_order_id', $order->id);

        return redirect()->route('pos.index')->with('status', "Order {$order->order_number} loaded for editing.");
    }

    /** Moves a completed order to Trash — reverses its stock and any
     *  customer-ledger due amount first (same as before), so stock/payment
     *  totals stay accurate while it's sitting in Trash. Restoring it from
     *  Trash puts those effects back; Permanently Deleting it from Trash
     *  just removes the row (its items cascade-delete) since the effects
     *  are already reversed. */
    public function destroy(Order $order)
    {
        DB::transaction(function () use ($order) {
            $order->reverseCompletedEffects();
            $order->delete();
        });

        return back()->with('status', "Order {$order->order_number} moved to Trash — stock and customer balance adjusted.");
    }

    /** Bulk version of destroy() for the checked rows on the Orders list. */
    public function destroySelected(Request $request)
    {
        $ids = (array) $request->input('ids', []);
        $orders = Order::whereIn('id', $ids)->get();

        DB::transaction(function () use ($orders) {
            foreach ($orders as $order) {
                $order->reverseCompletedEffects();
                $order->delete();
            }
        });

        return back()->with('status', count($orders) . ' order(s) moved to Trash — stock and customer balances adjusted.');
    }

    /** Bulk version of destroy() for every order matching the current
     *  date-range/search filter (not the whole table). */
    public function destroyAll(Request $request)
    {
        [$query] = $this->filteredQuery($request);
        $orders = $query->get();

        DB::transaction(function () use ($orders) {
            foreach ($orders as $order) {
                $order->reverseCompletedEffects();
                $order->delete();
            }
        });

        return back()->with('status', count($orders) . ' order(s) moved to Trash — stock and customer balances adjusted.');
    }

    /** Order-level credit/debit ledger — the walk-in equivalent of a named
     *  customer's ledger (customers.credit_balance + Customer::payments()).
     *  A named customer's due carries on their own running balance across
     *  every order they ever make; a walk-in sale has no such person to
     *  attach a balance to, so this tracks it against the order itself
     *  instead — same credit/debit mechanics, just scoped to one order's
     *  due_amount rather than a customer's lifetime balance. */
    public function ledger(Order $order)
    {
        $payments = $order->payments()->with('user')->latest()->get();
        return view('orders.ledger', compact('order', 'payments'));
    }

    /** Credit = payment received against what's still due — due_amount
     *  goes down, paid_amount goes up. Debit = an additional charge on top
     *  of the original sale (e.g. a late fee) — due_amount goes up. Mirrors
     *  CustomerController::recordPayment()'s validation and semantics
     *  exactly, just applied to one order's due instead of a running
     *  customer balance. */
    public function recordPayment(Request $request, Order $order)
    {
        $data = $request->validate([
            'type' => ['required', 'in:credit,debit'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        // FIXED (H4): the order ledger and the customer's running balance
        // used to be two ledgers for the same debt, and only the order
        // one was updated here — Capital Receivables and the POS
        // "previous balance" (both read customers.credit_balance) stayed
        // wrong until someone happened to open that customer's ledger
        // page (the only place syncBalance() runs). Now the payment row,
        // the order totals AND the customer balance move in ONE
        // transaction, so they can never drift apart mid-way.
        //
        // One deliberate scope limit: the customer balance is adjusted
        // only when this order is COMPLETED. For an in_progress order the
        // due has not been added to credit_balance yet (that happens at
        // complete()), so adjusting it here would double-count — the
        // completion step adds the (then current) due exactly once.
        // Walk-in orders have no customer, so nothing extra is adjusted.
        //
        // v3.1.0 (M8): the order row is locked inside the transaction
        // and the credit cap is re-validated against the LOCKED due —
        // the old pre-transaction cap read a stale due, so two
        // simultaneous credits could both pass it and over-collect.
        // Lock + re-check also defeats a sequential double-click: the
        // second POST sees the reduced due and fails the re-check, so
        // no separate idempotency key is needed for this endpoint.
        // Status: in_progress/hold orders are INTENTIONALLY still
        // allowed — POSController::complete() explicitly reconciles
        // pre-existing ledger payments at completion (v2.2 R1 delta
        // row), which only makes sense if payments can pre-date
        // completion, and the scope comment above contemplates
        // in_progress payments. Only 'cancelled' is rejected: a
        // cancelled order never happened (and initiateOnlinePayment
        // already refuses cancelled orders), so no payment against it
        // is ever legitimate. POS complete() itself never calls this
        // method — it writes OrderPayment rows directly.
        DB::transaction(function () use ($order, $data) {
            $locked = Order::lockForUpdate()->findOrFail($order->id);

            if ($locked->status === 'cancelled') {
                throw ValidationException::withMessages(['error' => 'Payments cannot be recorded on a cancelled order.']);
            }
            if ($data['type'] === 'credit' && $data['amount'] > (float) $locked->due_amount) {
                throw ValidationException::withMessages(['amount' => "Amount ({$data['amount']}) is more than what's still due on this order ({$locked->due_amount})."]);
            }

            $locked->payments()->create([
                'type' => $data['type'],
                'amount' => $data['amount'],
                'note' => $data['note'] ?? null,
                'user_id' => auth()->id(),
            ]);

            if ($data['type'] === 'credit') {
                $locked->decrement('due_amount', $data['amount']);
                $locked->increment('paid_amount', $data['amount']);
                if ($locked->customer_id && $locked->status === 'completed') {
                    // Locked when present; a trashed/missing customer
                    // keeps the old silent no-op (the order payment
                    // itself still records) instead of 404ing.
                    Customer::lockForUpdate()->find($locked->customer_id)?->decrement('credit_balance', $data['amount']);
                }
            } else {
                $locked->increment('due_amount', $data['amount']);
                if ($locked->customer_id && $locked->status === 'completed') {
                    Customer::lockForUpdate()->find($locked->customer_id)?->increment('credit_balance', $data['amount']);
                }
            }
        });

        $label = $data['type'] === 'credit' ? 'Payment recorded' : 'Charge added';
        return back()->with('status', "{$label}: {$data['amount']}. Order balance updated.");
    }

    /** FEATURE 9 — start a JazzCash/Easypaisa payment for this order.
     *  The ONLY method added to OrderController by this feature; every
     *  other method above is the v2.2 version untouched.
     *
     *  v3.1.0 (H4): access is admin/manager ONLY — the old "or the
     *  order's cashier" branch is removed (see initiateOnlinePayment
     *  below). The route also carries role:admin,manager middleware.
     *
     *  Validates gateway (jazzcash/easypaisa only) and amount > 0, and
     *  — beyond the spec's minimum, because recordPayment() itself
     *  rejects a credit above the due — also rejects an amount above
     *  the order's current due_amount and any payment on a cancelled
     *  order. Then it delegates to PaymentGatewayService::initiate()
     *  (which creates the PENDING OnlinePayment row) and renders the
     *  gateway's hosted-checkout form view. No order total, status, or
     *  ledger row is changed here: money is only recorded later, by a
     *  verified callback in OnlinePaymentController. */
    public function initiateOnlinePayment(Request $request, Order $order)
    {
        // v3.1.0 (H4): admin/manager ONLY. The old "or the order's
        // cashier" branch is removed: a cashier must never reach the
        // gateway hosted-checkout form. The pp_Password field in that
        // form is deliberately NOT stripped — the gateway requires it
        // in the browser POST; the role gate above is the fix.
        $user = $request->user();
        if (!in_array($user->role, ['admin', 'manager'], true)) {
            abort(403, 'You do not have permission to access this page.');
        }

        if ($order->status === 'cancelled') {
            return back()->withErrors(['error' => 'Online payments cannot be started for a cancelled order.']);
        }

        $data = $request->validate([
            'gateway' => ['required', 'in:jazzcash,easypaisa'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        if ($data['amount'] > (float) $order->due_amount) {
            return back()->withErrors(['amount' => "Amount ({$data['amount']}) is more than what's still due on this order ({$order->due_amount})."]);
        }

        $result = app(PaymentGatewayService::class)->initiate(
            $order, $data['gateway'], (float) $data['amount'], $user->id
        );

        return view('orders.online-payment-redirect', [
            'order' => $order,
            'payment' => $result['payment'],
            'gateway' => $result['gateway'],
            'action' => $result['action'],
            'fields' => $result['fields'],
            'is_live' => $result['is_live'],
        ]);
    }
}
