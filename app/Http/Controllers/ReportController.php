<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Expense;
use App\Models\CashParty;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /** Reports hub — links out to each report type. */
    public function index(Request $request)
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());

        $orders = Order::where('status', 'completed')
            ->whereBetween('created_at', [$from, $to . ' 23:59:59']);

        $grossSales = (float) (clone $orders)->sum('total');
        $totalReturnsValue = (float) (\App\Models\StockReturn::where('type', 'from_customer')
            ->whereBetween('created_at', [$from, $to . ' 23:59:59'])
            ->selectRaw('SUM(quantity * unit_price - discount_amount) as total')
            ->value('total') ?? 0);
        // Deliberately NOT floored to 0 — clamping a bigger-than-sales return
        // total down to a flat "0.00" made it look like sales data had gone
        // missing, when what actually happened was returns in this date
        // range outweighing sales in it. Showing the true (possibly
        // negative) net, alongside the gross figure and the returns figure
        // below, makes that visible instead of hiding it.
        $totalSales = $grossSales - $totalReturnsValue;
        $totalOrders = (clone $orders)->count();

        $bestSelling = OrderItem::select('product_id', DB::raw('SUM(quantity) as qty_sold'))
            ->whereHas('order', fn ($q) => $q->where('status', 'completed')->whereBetween('created_at', [$from, $to . ' 23:59:59']))
            ->groupBy('product_id')
            ->with('product')
            ->get()
            ->map(function ($row) use ($from, $to) {
                $returned = \App\Models\StockReturn::where('product_id', $row->product_id)
                    ->where('type', 'from_customer')
                    ->whereBetween('created_at', [$from, $to . ' 23:59:59'])
                    ->sum('quantity');
                $row->qty_sold = max(0, $row->qty_sold - $returned);
                return $row;
            })
            ->sortByDesc('qty_sold')
            ->filter(fn ($row) => $row->qty_sold > 0)
            ->take(10)
            ->values();

        $cashierSales = Order::where('status', 'completed')
            ->whereBetween('created_at', [$from, $to . ' 23:59:59'])
            ->select('user_id', DB::raw('SUM(total) as total_sales'), DB::raw('COUNT(*) as order_count'))
            ->groupBy('user_id')
            ->with('cashier')
            ->get();

        return view('reports.index', compact('from', 'to', 'grossSales', 'totalReturnsValue', 'totalSales', 'totalOrders', 'bestSelling', 'cashierSales'));
    }

    public function cashReconciliation(Request $request)
    {
        $date = $request->input('date', today()->toDateString());
        $recordedCash = Order::where('status', 'completed')
            ->where('payment_method', 'cash')
            ->whereDate('created_at', $date)
            ->sum('paid_amount');

        $countedCash = null;
        $mismatch = null;
        if ($request->filled('counted_cash')) {
            $countedCash = (float) $request->counted_cash;
            $mismatch = round($countedCash - $recordedCash, 2);
        }

        return view('reports.cash_reconciliation', compact('date', 'recordedCash', 'countedCash', 'mismatch'));
    }

    /** Stock report: current stock, value, and low/out-of-stock flags for every product. */
    public function stock(Request $request)
    {
        $filter = $request->input('filter', 'all'); // all | out | available
        $query = Product::active();

        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }
        if ($filter === 'out') {
            $query->where('stock', '<=', 0);
        } elseif ($filter === 'available') {
            $query->where('stock', '>', 0);
        }

        $products = $query->orderBy('name')->get();
        $totalStockValue = $products->sum(fn ($p) => $p->stock * $p->net_purchase_price);
        $outOfStockCount = Product::active()->where('stock', '<=', 0)->count();
        $availableCount = Product::active()->where('stock', '>', 0)->count();

        return view('reports.stock', compact('products', 'totalStockValue', 'filter', 'outOfStockCount', 'availableCount'));
    }

    /** Purchase report: all purchase orders with supplier + line item breakdown.
     *
     *  "Total Purchase Value" is the sum of each RECEIVED PO's own `total`
     *  column — the same figure the Capital Report's "Stock Purchased"
     *  counts — so the two reconcile instead of drifting apart:
     *   - A pending (not-yet-received) PO hasn't actually put anything into
     *     stock yet, so it doesn't belong in either figure. It used to be
     *     summed in here regardless of status, which could make this page
     *     show more than the Capital Report even for the exact same range.
     *   - Relying on the stored `total` column (rather than recomputing
     *     just the line-item subtotal here) is only safe because
     *     PurchaseOrderController now keeps it correct at every point it
     *     can change: recalculated the moment a PO is received, on every
     *     view of its show page, on every load of the PO list, and after
     *     any line/discount edit. That also means it correctly reflects
     *     any PO-level (header) discount on top of the per-line ones,
     *     which a bare item-level subtotal here would have missed.
     *  Pending POs are still listed (and their estimated value shown per-PO
     *  as before) so nothing disappears from view — they just don't feed
     *  the headline totals until they're actually received. */
    public function purchases(Request $request)
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());

        $purchaseOrders = PurchaseOrder::with('supplier', 'items.product')
            ->whereBetween('created_at', [$from, $to . ' 23:59:59'])
            ->latest()
            ->get();

        $supplierReturns = \App\Models\StockReturn::with('product', 'supplier')
            ->where('type', 'to_supplier')
            ->whereBetween('created_at', [$from, $to . ' 23:59:59'])
            ->latest()
            ->get();

        $totalPurchaseValue = (float) $purchaseOrders->where('status', 'received')->sum('total');
        $pendingValue = (float) $purchaseOrders->where('status', '!=', 'received')->sum('total');
        $totalReturnedValue = $supplierReturns->sum(fn ($r) => $r->net_amount);
        $netPurchaseValue = $totalPurchaseValue - $totalReturnedValue;

        return view('reports.purchases', compact(
            'purchaseOrders', 'supplierReturns', 'totalPurchaseValue', 'pendingValue',
            'totalReturnedValue', 'netPurchaseValue', 'from', 'to'
        ));
    }

    /** Per-customer report: pick a customer to see their full order + payment history. */
    public function customers(Request $request)
    {
        Order::purgeStaleInProgress();

        $customers = Customer::withCount(['orders' => fn ($q) => $q->where('status', '!=', 'in_progress')])
            ->withSum(['orders as total_purchased' => fn ($q) => $q->where('status', 'completed')], 'total')
            ->orderByDesc('total_purchased')
            ->get();

        // Walk-in orders/returns are the ones with no customer_id at all —
        // not a Customer row, so they never show up in the list above.
        // Surfaced here as its own summary instead.
        $walkIn = [
            'orders_count' => Order::whereNull('customer_id')->where('status', 'completed')->count(),
            'total_purchased' => (float) Order::whereNull('customer_id')->where('status', 'completed')->sum('total'),
        ];

        return view('reports.customers', compact('customers', 'walkIn'));
    }

    /** Same idea as customerDetail(), but for walk-in sales — orders and
     *  returns with no customer_id, so they can't be looked up via a
     *  Customer model/route-binding the way customerDetail() does. */
    public function walkInDetail()
    {
        Order::purgeStaleInProgress();

        // "in_progress" means someone's POS cart is literally open right now —
        // not a finished sale, so it doesn't belong in a sales report. Anything
        // that was actually completed, explicitly held as a draft, or cancelled
        // still shows here (and can be deleted from this screen if needed).
        $orders = Order::whereNull('customer_id')->where('status', '!=', 'in_progress')
            ->with('items.product')->latest()->paginate(15);
        $totalPurchased = (float) Order::whereNull('customer_id')->where('status', 'completed')->sum('total');
        $returns = \App\Models\StockReturn::whereNull('customer_id')->where('type', 'from_customer')->with('product')->latest()->get();

        return view('reports.walk_in_detail', compact('orders', 'totalPurchased', 'returns'));
    }

    public function customerDetail(Customer $customer)
    {
        Order::purgeStaleInProgress();

        $orders = $customer->orders()->where('status', '!=', 'in_progress')
            ->with('items.product')->latest()->paginate(15);
        $totalPurchased = $customer->orders()->where('status', 'completed')->sum('total');
        $totalDue = $customer->orders()->where('status', 'completed')->sum('due_amount');
        $returns = \App\Models\StockReturn::where('customer_id', $customer->id)->with('product')->latest()->get();

        return view('reports.customer_detail', compact('customer', 'orders', 'totalPurchased', 'totalDue', 'returns'));
    }

    /** "Total Capital" report — read-only view of the same Investment vs
     *  Expenses numbers the Expenses page tracks (see ExpenseController),
     *  but framed as a report: a date-range view with a category breakdown
     *  and the full entry list, no add-expense form. Managing entries still
     *  happens on the Expenses page itself. */
    public function capital(Request $request)
    {
        $from = $request->input('from') ?: now()->startOfMonth()->toDateString();
        $to = $request->input('to') ?: now()->toDateString();
        $year = (int) ($request->input('year') ?: now()->year);

        // --- Current overall standing (all-time snapshot, not scoped to
        // the date filter — see ExpenseController::index() for the same
        // reasoning) ---
        // Stock Value must use the NET cost per unit (purchase_price after
        // purchase_discount_percent) — see Product::net_purchase_price —
        // since purchase_price alone is the GROSS supplier rate.
        $stockValue = (float) Product::query()->get()->sum(fn ($p) => $p->stock * $p->net_purchase_price);
        $cashInjected = (float) Expense::where('type', 'cash_in')->sum('amount');

        // Total Investment is a CONSTANT, historical figure: every rupee
        // ever put into the business, whether as stock purchases or cash
        // injections. It never moves when a product sells — that's the
        // whole point of it (a running "how much have we invested so far"
        // total). So it's built from the sum of every RECEIVED PO's own
        // `total` column (which PurchaseOrderController keeps correct at
        // every point it can change — see the comment on
        // ReportController::purchases() for why that's now safe to rely
        // on directly, header discounts included) plus cash injected.
        $cumulativePurchaseCost = (float) PurchaseOrder::where('status', 'received')->sum('total');
        $totalInvestment = $cumulativePurchaseCost + $cashInjected;

        // Remaining Investment is what's actually still tied up in the
        // business right now: current stock value (this DOES shrink as
        // products sell) plus cash injected (that doesn't get "spent" by a
        // sale, so it stays in both figures).
        $remainingInvestment = $stockValue + $cashInjected;
        // Cash Out only — Cash In is a capital injection, not an expense
        // (see ExpenseController::index for the same reasoning). Summing
        // both used to overstate Total Expenses by whatever had been added
        // as capital.
        $totalExpenses = (float) Expense::where('type', 'cash_out')->sum('amount');
        // Cash Management (personal borrowing tracker) was built deliberately
        // isolated from the rest of the system, but "liabilities" is
        // meaningless without it — money owed to people is a real liability
        // against the business's capital, so it's pulled in here (read-only)
        // for a complete picture. It's still managed entirely on its own
        // Cash Management page; nothing here writes back to it.
        $totalLiabilities = (float) CashParty::sum('balance');
        // Money customers owe THE BUSINESS (a wholesale customer's running
        // tab — credit_balance, raised either by an unpaid due_amount on a
        // real order, or by a manual debit/charge entered on their ledger
        // with no order at all — e.g. an opening balance carried over from
        // before this system). Either way it's a real receivable asset:
        // goods or value already went out, cash for it hasn't come back
        // yet. Mirrors Liabilities just above (money owed TO others is
        // subtracted here), so money owed BY others is added — without
        // this, recording a customer debit with no matching order visibly
        // did nothing to the business's standing, which is what made it
        // look like a bug rather than an incomplete picture.
        $totalReceivables = (float) Customer::sum('credit_balance');
        // Net Capital is meant to reflect true current standing (equity):
        // Remaining Investment, plus what customers still owe (an asset),
        // minus what's been spent AND what's owed to others (liabilities).
        $netCapital = $remainingInvestment + $totalReceivables - $totalExpenses - $totalLiabilities;

        // --- Standard Margin (Revenue − Cost of Goods Sold). The old
        // "Profit & Loss" card (discount-differential formula) was removed
        // per request — this is now the only profit figure on the report.
        //
        // Revenue is the ACTUAL amount each completed order settled for
        // (orders.total), which already nets out both the per-item
        // discount (order_items.discount_percent) and the customer-level
        // discount (orders.discount_percent — e.g. a wholesale customer's
        // standing discount vs a walk-in customer's 0%). Pulling it from
        // products.sale_price instead (the CURRENT catalog price) used to
        // silently assume every sale went out at full list price, which
        // erased exactly the margin difference between a discounted
        // wholesale sale and a full-price walk-in sale.
        //
        // COGS still comes from products.purchase_price rather than a
        // per-sale snapshot, because that field (together with
        // purchase_discount_percent — see Product::net_purchase_price) is
        // kept as the CURRENT net cost after the supplier's discount is
        // applied (see PurchaseOrderController@receive) — so the supplier
        // discount is already baked in here. The one remaining
        // approximation is that this uses the CURRENT purchase price for
        // all historical sales (no historical cost snapshot per sale
        // exists yet), so COGS shifts if a product's cost is edited after
        // the sale. Nothing here is cached: it's a live query run fresh on
        // every page load. ---
        $totalRevenue = (float) Order::where('status', 'completed')->sum('total');
        $approxCogs = (float) OrderItem::join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.status', 'completed')
            ->sum(DB::raw('order_items.quantity * products.purchase_price * (1 - COALESCE(products.purchase_discount_percent, 0) / 100)'));
        $approxGrossMargin = $totalRevenue - $approxCogs;
        $approxNetProfit = $approxGrossMargin - $totalExpenses;

        $monthly = $this->monthlyCapitalBreakdown($year);

        // --- Period log (still date-filtered — this part is a browsable
        // history, unlike the snapshot figures above) ---
        $query = Expense::whereDate('expense_date', '>=', $from)->whereDate('expense_date', '<=', $to);

        $byCategory = (clone $query)
            ->selectRaw("COALESCE(category, 'Uncategorized') as category, type, SUM(amount) as total")
            ->groupBy('category', 'type')
            ->get()
            ->groupBy('category')
            ->map(fn ($rows) => [
                'cash_in' => (float) $rows->firstWhere('type', 'cash_in')?->total,
                'cash_out' => (float) $rows->firstWhere('type', 'cash_out')?->total,
            ]);

        $expenses = (clone $query)->with('user')->latest('expense_date')->latest('id')->get();

        return view('reports.capital', compact(
            'totalInvestment', 'cumulativePurchaseCost', 'remainingInvestment', 'totalExpenses', 'netCapital', 'stockValue', 'cashInjected', 'totalLiabilities', 'totalReceivables',
            'totalRevenue', 'approxCogs', 'approxGrossMargin', 'approxNetProfit',
            'monthly', 'year', 'byCategory', 'expenses', 'from', 'to'
        ));
    }

    /** One row per calendar month of the given year: that month's Cash In/
     *  Cash Out, Standard-Margin revenue (each order's actual settled
     *  `total`, discounts already netted out) / COGS (each item's
     *  quantity at the product's CURRENT net purchase price — same
     *  approach as the all-time figure above), and the net profit after
     *  that month's expenses. Grouped in PHP after a single fetch per
     *  source (rather than DB-specific date-grouping functions, which
     *  differ between MySQL and SQLite) — fine at the scale a single
     *  shop's yearly data runs to. */
    private function monthlyCapitalBreakdown(int $year): array
    {
        $months = [];
        foreach (range(1, 12) as $m) {
            $months[$m] = [
                'month' => $m,
                'label' => \Carbon\Carbon::create($year, $m, 1)->format('M Y'),
                'cash_in' => 0.0, 'cash_out' => 0.0,
                'revenue' => 0.0, 'cogs' => 0.0,
                'expenses' => 0.0, 'gross_margin' => 0.0, 'net_profit' => 0.0,
            ];
        }

        Expense::whereYear('expense_date', $year)->get()->each(function ($e) use (&$months) {
            $m = $e->expense_date->month;
            if ($e->type === 'cash_in') {
                $months[$m]['cash_in'] += (float) $e->amount;
            } else {
                $months[$m]['cash_out'] += (float) $e->amount;
                // Cash In is a capital injection, not an expense — only
                // Cash Out counts toward the month's expenses/net profit
                // (see capital() above for the same reasoning).
                $months[$m]['expenses'] += (float) $e->amount;
            }
        });

        Order::where('status', 'completed')
            ->whereYear(DB::raw('COALESCE(original_completed_at, created_at)'), $year)
            ->with('items.product')
            ->get()
            ->each(function ($order) use (&$months) {
                $m = ($order->original_completed_at ?? $order->created_at)->month;
                // Revenue is the order's actual settled total, not
                // quantity × current list price — see capital() above.
                $months[$m]['revenue'] += (float) $order->total;
                $order->items->each(function ($item) use (&$months, $m) {
                    if (!$item->product) return;
                    $months[$m]['cogs'] += $item->quantity * $item->product->net_purchase_price;
                });
            });

        foreach ($months as $m => $row) {
            $margin = $row['revenue'] - $row['cogs'];
            $months[$m]['gross_margin'] = round($margin, 2);
            $months[$m]['net_profit'] = round($margin - $row['expenses'], 2);
            $months[$m]['revenue'] = round($row['revenue'], 2);
            $months[$m]['cogs'] = round($row['cogs'], 2);
        }

        return array_values($months);
    }
}
