<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
                    $stray->items()->count() > 0 ? $stray->update(['status' => 'hold']) : $stray->delete();
                });

            $order->reverseCompletedEffects();
            $order->update(['status' => 'in_progress']);
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
}
