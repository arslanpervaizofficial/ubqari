<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Customer;
use App\Models\StockReturn;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /** Completed sales total for a date, minus the value of any customer returns that same day —
     *  so a return actually pulls the sales figures back down, not just the stock/ledger.
     *  FIXED (v2, M13): the returns sum used to be quantity × unit_price
     *  WITHOUT subtracting discount_amount, so every returned item's
     *  discount was counted as if it were still revenue lost — and the
     *  max(0, ...) floor then hid genuinely negative days (returns
     *  outweighing sales) that the Reports hub correctly shows. Both are
     *  fixed: returns now use the same net_amount formula as the hub
     *  (quantity × unit_price − discount_amount) and the result is NOT
     *  floored, so a negative day shows as negative. */
    private function netSalesForDate($date): float
    {
        $sales = (float) Order::where('status', 'completed')->whereDate('created_at', $date)->sum('total');
        $returns = (float) StockReturn::where('type', 'from_customer')
            ->whereDate('created_at', $date)
            ->selectRaw('SUM(quantity * unit_price - discount_amount) as total')
            ->value('total');
        return $sales - $returns;
    }

    public function index()
    {
        $todaySales = $this->netSalesForDate(today());

        // Same M13 fix as netSalesForDate() above: net returns (discount
        // subtracted) and no max(0, ...) floor, so the month figure and
        // the hub figure use the same formula.
        $monthSales = (float) Order::where('status', 'completed')->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->sum('total');
        $monthReturns = (float) StockReturn::where('type', 'from_customer')
            ->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)
            ->selectRaw('SUM(quantity * unit_price - discount_amount) as total')
            ->value('total');
        $monthSales = $monthSales - $monthReturns;

        // v2.2 (F11): low-stock excludes out-of-stock (stock > 0) —
        // the two cards used to count the same products twice.
        $lowStock = Product::active()->whereColumn('stock', '<=', 'min_stock')->whereNotNull('min_stock')->where('stock', '>', 0)->get();
        $outOfStock = Product::active()->where('stock', '<=', 0)->get();

        $suggestQty = function ($p) {
            $target = $p->max_stock ?? (($p->min_stock ?? 0) * 2) ?: 10;
            // v2.2 (F11): no max(1, ...) floor any more — a product
            // already at/above target suggests 0, not 1.
            return max(0, round($target - $p->stock));
        };
        $lowStock->each(fn ($p) => $p->suggested_qty = $suggestQty($p));
        $outOfStock->each(fn ($p) => $p->suggested_qty = $suggestQty($p));
        // v2.2 (F1/F11): quotations are estimates, not held orders.
        $heldOrdersCount = Order::where('status', 'hold')->where('is_quotation', false)->count();
        $totalProducts = Product::active()->count();
        $totalCustomers = Customer::count();

        $last7Days = collect(range(6, 0))->map(function ($daysAgo) {
            $date = now()->subDays($daysAgo);
            return [
                'label' => $date->format('D'),
                'total' => $this->netSalesForDate($date),
            ];
        });

        // Top parties (customers) by total completed purchases
        $topParties = Customer::withSum(['orders as total_purchased' => fn ($q) => $q->where('status', 'completed')], 'total')
            ->orderByDesc('total_purchased')
            ->limit(5)
            ->get()
            ->filter(fn ($c) => $c->total_purchased > 0)
            ->values();

        // Top products by quantity sold, net of any customer returns
        $topProducts = OrderItem::select('product_id', DB::raw('SUM(quantity) as qty_sold'))
            ->whereHas('order', fn ($q) => $q->where('status', 'completed'))
            ->groupBy('product_id')
            ->with('product')
            ->get()
            ->map(function ($row) {
                $returned = \App\Models\StockReturn::where('product_id', $row->product_id)
                    ->where('type', 'from_customer')
                    ->sum('quantity');
                $row->qty_sold = max(0, $row->qty_sold - $returned);
                return $row;
            })
            ->sortByDesc('qty_sold')
            ->filter(fn ($row) => $row->qty_sold > 0)
            ->take(5)
            ->values();

        return view('dashboard', compact(
            'todaySales', 'monthSales', 'lowStock', 'outOfStock',
            'heldOrdersCount', 'totalProducts', 'totalCustomers', 'last7Days',
            'topParties', 'topProducts'
        ));
    }
}
