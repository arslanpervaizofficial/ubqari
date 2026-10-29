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
use Illuminate\Support\Facades\Schema;

class ReportController extends Controller
{
    /** Reports hub — links out to each report type. */
    public function index(Request $request)
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());

        // FIXED (v2, H24): filter completed orders by when the sale
        // actually completed, not when the cart was opened. A cart opened
        // 30 Sep and completed 2 Oct used to count in September here
        // while the Capital breakdowns counted it in October (they
        // already use COALESCE(original_completed_at, created_at)).
        // original_completed_at is null for orders completed before that
        // column existed, so COALESCE falls back to created_at.
        $completedAt = DB::raw('COALESCE(original_completed_at, created_at)');
        $orders = Order::where('status', 'completed')
            ->whereBetween($completedAt, [$from, $to . ' 23:59:59']);

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
            ->whereHas('order', fn ($q) => $q->where('status', 'completed')->whereBetween(DB::raw('COALESCE(original_completed_at, created_at)'), [$from, $to . ' 23:59:59']))
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
            ->whereBetween($completedAt, [$from, $to . ' 23:59:59'])
            ->select('user_id', DB::raw('SUM(total) as total_sales'), DB::raw('COUNT(*) as order_count'))
            ->groupBy('user_id')
            ->with('cashier')
            ->get();

        // FIXED (v2, M15): the cashier column used to be gross while the
        // page total above is net of returns, so the column never added
        // up to the page total. Returns are grouped by the ORIGINAL
        // order's cashier (via stock_returns.order_id) so the view can
        // show a net figure next to each gross figure. Returns with no
        // order link cannot be attributed to a cashier; they still count
        // in the page-level Total Sales above, which is why a cashier
        // net column and the page total can differ by that unattributed
        // amount — the view says so explicitly.
        $cashierReturns = \App\Models\StockReturn::where('type', 'from_customer')
            ->whereBetween('created_at', [$from, $to . ' 23:59:59'])
            ->whereNotNull('order_id')
            ->join('orders', 'stock_returns.order_id', '=', 'orders.id')
            ->select('orders.user_id', DB::raw('SUM(stock_returns.quantity * stock_returns.unit_price - stock_returns.discount_amount) as returns_total'))
            ->groupBy('orders.user_id')
            ->pluck('returns_total', 'user_id')
            ->map(fn ($v) => (float) $v)
            ->all();

        return view('reports.index', compact('from', 'to', 'grossSales', 'totalReturnsValue', 'totalSales', 'totalOrders', 'bestSelling', 'cashierSales', 'cashierReturns'));
    }

    public function cashReconciliation(Request $request)
    {
        $date = $request->input('date', today()->toDateString());

        // FIXED (v2, H23 + H24 + M14) — three separate bugs lived in the
        // old one-liner (payment_method=cash, created_at, full paid_amount):
        // (1) H23: the cash half of a split payment was invisible, because
        // only payment_method='cash' rows were summed and the split cash
        // amount was stored nowhere. Now the cash portion per order is:
        // orders.cash_amount when that column exists and is set, else
        // paid_amount for cash orders, else 0 (bank-only / split rows
        // from before the cash_amount column existed contribute 0 rather
        // than a guessed split — see CHANGES.txt).
        // (2) H24: the date filter now uses the completion date
        // (COALESCE(original_completed_at, created_at)), matching the
        // Capital breakdowns, instead of the cart-opened date.
        // (3) M14: for walk-in orders (no customer row) an overpayment is
        // change handed back, not till cash — so a walk-in order's cash
        // contribution is capped at its total. For a named customer an
        // overpayment is a genuine advance that stays in the till, so it
        // is NOT capped. Computed per order in PHP (one day's orders, a
        // small set) so the walk-in cap and the column fallback stay
        // readable instead of being buried in a SQL CASE.
        $hasCashAmount = Schema::hasColumn('orders', 'cash_amount');
        $columns = ['customer_id', 'payment_method', 'paid_amount', 'total'];
        if ($hasCashAmount) {
            $columns[] = 'cash_amount';
        }
        $recordedCash = 0.0;
        Order::where('status', 'completed')
            ->whereDate(DB::raw('COALESCE(original_completed_at, created_at)'), $date)
            ->get($columns)
            ->each(function ($order) use (&$recordedCash, $hasCashAmount) {
                if ($hasCashAmount && $order->cash_amount !== null) {
                    $cash = (float) $order->cash_amount;
                } elseif ($order->payment_method === 'cash') {
                    $cash = (float) $order->paid_amount;
                } else {
                    $cash = 0.0;
                }
                if ($order->customer_id === null) {
                    $cash = min($cash, (float) $order->total);
                }
                $recordedCash += $cash;
            });
        $recordedCash = round($recordedCash, 2);

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

        // FIXED 2026-10-29 (v1.4): paginate at 20 per page instead of
        // loading every product on one long scroll. Total Stock Value
        // MUST still be computed over the full filtered set, not just
        // the current page — computing it from the paginated collection
        // would silently understate it. withQueryString() keeps the
        // search/filter on every page link.
        $totalStockValue = (clone $query)->get()->sum(fn ($p) => $p->stock * $p->net_purchase_price);
        $products = $query->orderBy('name')->paginate(20)->withQueryString();
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

        // FIXED (v2, H24): a purchase order used to be counted in the
        // month it was CREATED, even if the stock arrived months later,
        // because purchase_orders had no received-date column to filter
        // on. Filter by received_at when that column exists (falling
        // back per-row to created_at for rows received before the column
        // existed), and by created_at when the column is absent entirely
        // (Schema::hasColumn guard, so this file works before the
        // migration that adds received_at is run).
        $poDate = Schema::hasColumn('purchase_orders', 'received_at')
            ? DB::raw('COALESCE(received_at, created_at)')
            : 'created_at';
        $purchaseOrders = PurchaseOrder::with('supplier', 'items.product')
            ->whereBetween($poDate, [$from, $to . ' 23:59:59'])
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

        // FIXED (v2, audit note): the named-customer order count used
        // status != 'in_progress', which counted held/draft orders, while
        // the walk-in count below is completed-only. Both are now
        // completed-only so the two figures mean the same thing.
        $customers = Customer::withCount(['orders' => fn ($q) => $q->where('status', 'completed')])
            ->withSum(['orders as total_purchased' => fn ($q) => $q->where('status', 'completed')], 'total')
            ->orderByDesc('total_purchased')
            ->get();

        // FIXED 2026-10-29 (v1.5): total_purchased is now NET of returns.
        // withSum above is gross (completed orders only); subtract each
        // customer's from_customer return net amounts, then re-sort, so
        // the list order reflects kept goods, not gross billing. No
        // flooring at zero: a return for a sale outside the system (or
        // recorded without an order) honestly shows as a negative net,
        // the same rule the Reports hub already uses for Total Sales.
        $returnsByCustomer = $this->customerReturns()->whereNotNull('customer_id')
            ->groupBy('customer_id')
            ->map(fn ($rows) => $rows->sum(fn ($r) => $r->net_amount));
        $customers->each(function ($c) use ($returnsByCustomer) {
            $c->total_purchased = (float) ($c->total_purchased ?? 0) - (float) ($returnsByCustomer[$c->id] ?? 0);
        });
        $customers = $customers->sortByDesc('total_purchased')->values();

        // Walk-in orders/returns are the ones with no customer_id at all —
        // not a Customer row, so they never show up in the list above.
        // Surfaced here as its own summary instead, also net of returns.
        $walkInReturns = $this->customerReturns()->whereNull('customer_id')->sum(fn ($r) => $r->net_amount);
        $walkIn = [
            'orders_count' => Order::whereNull('customer_id')->where('status', 'completed')->count(),
            'total_purchased' => (float) Order::whereNull('customer_id')->where('status', 'completed')->sum('total') - $walkInReturns,
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
        $returns = \App\Models\StockReturn::whereNull('customer_id')->where('type', 'from_customer')->with('product')->latest()->get();
        // FIXED 2026-10-29 (v1.5): Total Purchased is net of walk-in
        // returns (the Returns card on this page already showed them
        // separately, but the headline total was still gross).
        $totalPurchased = (float) Order::whereNull('customer_id')->where('status', 'completed')->sum('total') - $returns->sum(fn ($r) => $r->net_amount);
        // FIXED 2026-10-29 (v1.5): walk-in dues are now surfaced. A
        // walk-in order has no customer row, so its due_amount used to
        // go nowhere — it never appeared in Capital Receivables. It is
        // kept current by the existing order ledger flow: recording a
        // payment on an order's Ledger page decrements its due_amount,
        // so this figure (and Capital Receivables) falls when the
        // walk-in customer pays. No POS behaviour was changed: walk-in
        // orders are NOT forced to full payment at completion.
        $totalDue = (float) Order::whereNull('customer_id')->where('status', 'completed')->sum('due_amount');

        return view('reports.walk_in_detail', compact('orders', 'totalPurchased', 'totalDue', 'returns'));
    }

    public function customerDetail(Customer $customer)
    {
        Order::purgeStaleInProgress();

        $orders = $customer->orders()->where('status', '!=', 'in_progress')
            ->with('items.product')->latest()->paginate(15);
        // FIXED 2026-10-29 (v1.5): Total Purchased is net of this
        // customer's returns. Total Due is deliberately left as the sum
        // of order due_amounts: after a return, the amount to actually
        // collect is the Credit Balance card (return credit notes land
        // there), while Total Due still shows the pre-return order dues.
        // If the two differ after a return, collect on Credit Balance.
        $totalPurchased = $customer->orders()->where('status', 'completed')->sum('total')
            - \App\Models\StockReturn::where('customer_id', $customer->id)->where('type', 'from_customer')->get()->sum(fn ($r) => $r->net_amount);
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

        // --- Total Investment: FIXED 2026-10-29 (v1.3) ---
        // Old formula was cumulative: sum of EVERY received PO's `total`
        // + cash injected. That never fell when stock sold, so the same
        // rupee got counted again every time it cycled: sell stock for
        // cash, use that cash to buy new stock, and the old formula added
        // the new purchase on top of the old one, even though no new
        // owner money came in. Example: buy 100,000 stock, sell it for
        // 120,000, buy 120,000 stock again with the sale cash — old Total
        // Investment said 220,000, but the owner only ever put 100,000 in
        // (plus 20,000 profit).
        //
        // New formula counts only what is CURRENTLY deployed in the
        // business, so a sale DOES move it:
        //   Total Investment = Current Stock Value + Cash Injected
        //                      + Total Receivables
        //   = Remaining Investment + Total Receivables
        // - Cash sale: stock falls by cost, receivables don't move, so
        //   Total falls by the cost sold (the cash goes to the till, which
        //   this system does not track as a separate balance — see the
        //   limitation note below).
        // - Credit sale: stock falls by cost, receivables rise by the sale
        //   price, so Total rises only by the profit on that sale.
        // - Repurchase with sale cash: stock rises again, but the earlier
        //   sale had already brought Total down, so nothing is double
        //   counted.
        // - New owner money (Expenses `cash_in`): Cash Injected rises, so
        //   Total, Remaining and Net Capital all rise.
        // - New borrowed money (CashParty balance): Liabilities rise and,
        //   once it is spent on stock, Total rises by the same stock
        //   value — so a borrowed investment shows in BOTH Total
        //   Investment and Liabilities, while Net Capital (below) is
        //   unchanged by borrowing, exactly as accounting requires:
        //   borrowing is funding, not equity.
        //
        // LIMITATION (honest, from the data this system actually holds):
        // there is no cash-till balance and no supplier-payment tracking
        // here, so till cash itself is not part of Total Investment — it
        // shows up only once it is turned into stock or a receivable.
        // Net Capital below is therefore the equity figure to trust, and
        // Total Investment is the "capital currently tied up in stock,
        // injected cash and credit" figure, not a full balance sheet.
        $cumulativePurchaseCost = (float) PurchaseOrder::where('status', 'received')->sum('total');
        // Retained only for reference / Purchase Report reconciliation —
        // it is NO LONGER part of Total Investment (see above).
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
        // FIXED 2026-10-29 (v1.5): Receivables now has TWO parts.
        // (1) Named customers: Customer::sum('credit_balance') as before.
        // (2) Walk-in dues: completed walk-in orders have no customer row,
        // so their due_amount used to go nowhere and never appeared in
        // Receivables at all. It is summed here from the orders
        // themselves, and it stays current because the existing order
        // ledger flow (Orders -> Ledger -> record payment) decrements
        // due_amount when a walk-in customer pays, so Receivables falls
        // as those dues are collected. No POS behaviour was changed:
        // walk-in orders are NOT forced to full payment at completion.
        $customerReceivables = (float) Customer::sum('credit_balance');
        $walkInDue = (float) Order::whereNull('customer_id')->where('status', 'completed')->sum('due_amount');
        $totalReceivables = $customerReceivables + $walkInDue;
        // Total Investment (new formula, see above): current stock +
        // injected cash + receivables. It is deliberately computed AFTER
        // $totalReceivables so the identity below holds.
        $totalInvestment = $remainingInvestment + $totalReceivables;
        // Net Capital is meant to reflect true current standing (equity).
        // With the new Total Investment this is simply:
        //   Net Capital = Total Investment - Expenses - Liabilities
        // which expands to exactly the old expression (Remaining +
        // Receivables - Expenses - Liabilities), so the Net Capital NUMBER
        // does not change with this fix — only the Total Investment card
        // does. Borrowing raises Total Investment and Liabilities by the
        // same amount and leaves Net Capital untouched.
        $netCapital = $totalInvestment - $totalExpenses - $totalLiabilities;

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
        // FIXED 2026-10-29 (v1.5): Revenue and COGS are now NET of
        // customer returns. Before this, a sale that was later fully
        // returned still counted its full profit here (revenue minus
        // cost), even though the goods came back and the money went back
        // (or the customer's credit was reduced). A fully returned sale
        // now contributes exactly zero margin: its net sale value comes
        // out of Revenue, and its returned quantity at current net cost
        // comes out of COGS. Returns with no product row left (product
        // deleted) still reduce Revenue; their COGS part is skipped
        // because their cost can no longer be priced.
        $allReturns = $this->customerReturns();
        $totalReturnsValue = $allReturns->sum(fn ($r) => $r->net_amount);
        $totalReturnsCogs = $allReturns->sum(fn ($r) => $r->product ? $r->quantity * $r->product->net_purchase_price : 0);
        $totalRevenue = (float) Order::where('status', 'completed')->sum('total') - $totalReturnsValue;
        $approxCogs = (float) OrderItem::join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.status', 'completed')
            ->sum(DB::raw('order_items.quantity * products.purchase_price * (1 - COALESCE(products.purchase_discount_percent, 0) / 100)')) - $totalReturnsCogs;
        $approxGrossMargin = $totalRevenue - $approxCogs;
        $approxNetProfit = $approxGrossMargin - $totalExpenses;

        $breakdownView = $request->input('view', 'yearly') === 'monthly' ? 'monthly' : 'yearly';
        $month = (int) ($request->input('month') ?: now()->month);
        $month = max(1, min(12, $month));
        $monthly = $breakdownView === 'monthly'
            ? $this->dailyCapitalBreakdown($year, $month)
            : $this->monthlyCapitalBreakdown($year);

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
            'totalInvestment', 'cumulativePurchaseCost', 'remainingInvestment', 'totalExpenses', 'netCapital', 'stockValue', 'cashInjected', 'totalLiabilities', 'totalReceivables', 'customerReceivables', 'walkInDue', 'totalReturnsValue', 'totalReturnsCogs',
            'totalRevenue', 'approxCogs', 'approxGrossMargin', 'approxNetProfit',
            'monthly', 'year', 'byCategory', 'expenses', 'from', 'to', 'breakdownView', 'month'
        ));
    }

    /** All customer returns (type from_customer), with their product so
     *  the returned cost can be priced at the product's CURRENT net
     *  purchase price — the same approximation the sales COGS below
     *  already uses (no historical cost snapshot per sale exists).
     *  net_amount is the StockReturn accessor: quantity * unit_price
     *  minus discount_amount, i.e. the money value of the returned goods.
     *  FIXED 2026-10-29 (v1.5): returns are now netted from customer
     *  totals and from Capital Revenue/COGS. Before this, a sale that
     *  was later fully returned still counted its full profit in Gross
     *  Margin, and a customer's "total_purchased" still counted goods
     *  they had given back. This does NOT double-count against
     *  Receivables: Receivables is a BALANCE (what is still owed), while
     *  Revenue / total_purchased are FLOWS (what was sold and kept) —
     *  a return correctly reduces both, for different reasons. A return
     *  credit note already reduces a named customer's credit_balance
     *  (see StockReturnController), so Receivables was already
     *  return-aware; only the flow figures were not. */
    private function customerReturns(): \Illuminate\Support\Collection
    {
        return \App\Models\StockReturn::where('type', 'from_customer')->with('product')->get();
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

        // FIXED 2026-10-29 (v1.5): net returns in the RETURN month (when
        // the refund/credit actually happened), not the sale month, so a
        // month's profit reflects what was kept in that month.
        $this->customerReturns()->each(function ($r) use (&$months, $year) {
            if ($r->created_at->year !== $year) return;
            $m = $r->created_at->month;
            $months[$m]['revenue'] -= $r->net_amount;
            if ($r->product) {
                $months[$m]['cogs'] -= $r->quantity * $r->product->net_purchase_price;
            }
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

    /** Same idea as monthlyCapitalBreakdown() above, but one row per DAY of
     *  the given month/year instead of one row per month of the year — used
     *  when the "Monthly" view is picked on the Capital Report, so you can
     *  see which specific day's sales drove that month's profit rather than
     *  only the month's total. */
    private function dailyCapitalBreakdown(int $year, int $month): array
    {
        $daysInMonth = \Carbon\Carbon::create($year, $month, 1)->daysInMonth;

        $days = [];
        foreach (range(1, $daysInMonth) as $d) {
            $days[$d] = [
                'day' => $d,
                'label' => \Carbon\Carbon::create($year, $month, $d)->format('d M'),
                'cash_in' => 0.0, 'cash_out' => 0.0,
                'revenue' => 0.0, 'cogs' => 0.0,
                'expenses' => 0.0, 'gross_margin' => 0.0, 'net_profit' => 0.0,
            ];
        }

        Expense::whereYear('expense_date', $year)->whereMonth('expense_date', $month)->get()->each(function ($e) use (&$days) {
            $d = $e->expense_date->day;
            if ($e->type === 'cash_in') {
                $days[$d]['cash_in'] += (float) $e->amount;
            } else {
                $days[$d]['cash_out'] += (float) $e->amount;
                $days[$d]['expenses'] += (float) $e->amount;
            }
        });

        Order::where('status', 'completed')
            ->whereYear(DB::raw('COALESCE(original_completed_at, created_at)'), $year)
            ->whereMonth(DB::raw('COALESCE(original_completed_at, created_at)'), $month)
            ->with('items.product')
            ->get()
            ->each(function ($order) use (&$days) {
                $d = ($order->original_completed_at ?? $order->created_at)->day;
                $days[$d]['revenue'] += (float) $order->total;
                $order->items->each(function ($item) use (&$days, $d) {
                    if (!$item->product) return;
                    $days[$d]['cogs'] += $item->quantity * $item->product->net_purchase_price;
                });
            });

        // FIXED 2026-10-29 (v1.5): same return netting as the monthly
        // breakdown, by return day.
        $this->customerReturns()->each(function ($r) use (&$days, $year, $month) {
            if ($r->created_at->year !== $year || $r->created_at->month !== $month) return;
            $d = $r->created_at->day;
            $days[$d]['revenue'] -= $r->net_amount;
            if ($r->product) {
                $days[$d]['cogs'] -= $r->quantity * $r->product->net_purchase_price;
            }
        });

        foreach ($days as $d => $row) {
            $margin = $row['revenue'] - $row['cogs'];
            $days[$d]['gross_margin'] = round($margin, 2);
            $days[$d]['net_profit'] = round($margin - $row['expenses'], 2);
            $days[$d]['revenue'] = round($row['revenue'], 2);
            $days[$d]['cogs'] = round($row['cogs'], 2);
        }

        return array_values($days);
    }
}
