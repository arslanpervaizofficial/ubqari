<?php

namespace App\Http\Controllers;

use App\Models\Customer;
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
        $totalStockValue = $products->sum(fn ($p) => $p->stock * $p->purchase_price);
        $outOfStockCount = Product::active()->where('stock', '<=', 0)->count();
        $availableCount = Product::active()->where('stock', '>', 0)->count();

        return view('reports.stock', compact('products', 'totalStockValue', 'filter', 'outOfStockCount', 'availableCount'));
    }

    /** Purchase report: all purchase orders with supplier + line item breakdown. */
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

        $totalPurchaseValue = $purchaseOrders->sum('total');
        $totalReturnedValue = $supplierReturns->sum(fn ($r) => $r->net_amount);
        $netPurchaseValue = $totalPurchaseValue - $totalReturnedValue;

        return view('reports.purchases', compact(
            'purchaseOrders', 'supplierReturns', 'totalPurchaseValue',
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
}
