<?php

namespace App\Http\Controllers;

use App\Models\CashParty;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Feature 8 — CSV exports for the report pages.
 *
 * These are CSV files, NOT real .xlsx workbooks (PhpSpreadsheet is not
 * installed and no new composer dependency was added). The download is
 * prefixed with a UTF-8 BOM so Microsoft Excel shows Urdu text correctly
 * instead of mojibake, and the filename ends in .csv.
 *
 * Each action mirrors its ReportController page as closely as possible:
 * same filters, same status rules, same stored-total figures. Where an
 * export deliberately differs from the page, NOTES.txt says so.
 *
 * Access control: the routes in routes-fragment.txt put these actions
 * behind 'auth' + 'role:admin,manager' — the same gate as the report
 * pages themselves. This controller does not add a second gate.
 */
class ReportExportController extends Controller
{
    // ------------------------------------------------------------------
    // Shared CSV plumbing
    // ------------------------------------------------------------------

    private function csvDownload(array $rows, string $filename): \Symfony\Component\HttpFoundation\Response
    {
        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response("\xEF\xBB\xBF" . $csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function money(float $value): string
    {
        // No thousands separator: Excel then parses the cell as a number
        // it can sum, instead of as text.
        return number_format($value, 2, '.', '');
    }

    private function fileDate(): string
    {
        return now()->toDateString();
    }

    // ------------------------------------------------------------------
    // Capital — mirrors ReportController::capital()
    // ------------------------------------------------------------------

    public function capital(Request $request)
    {
        $from = $request->input('from') ?: now()->startOfMonth()->toDateString();
        $to = $request->input('to') ?: now()->toDateString();
        $year = (int) ($request->input('year') ?: now()->year);

        $stockValue = (float) Product::query()->get()->sum(fn ($p) => $p->stock * $p->net_purchase_price);
        $cashInjected = (float) Expense::where('type', 'cash_in')->sum('amount');
        $cumulativePurchaseCost = (float) PurchaseOrder::where('status', 'received')->sum('total');
        $totalInvestment = $cumulativePurchaseCost + $cashInjected;
        $remainingInvestment = $stockValue + $cashInjected;
        $totalExpenses = (float) Expense::where('type', 'cash_out')->sum('amount');
        $totalLiabilities = (float) CashParty::sum('balance');
        $totalReceivables = (float) Customer::sum('credit_balance');
        $netCapital = $remainingInvestment + $totalReceivables - $totalExpenses - $totalLiabilities;

        $totalRevenue = (float) Order::where('status', 'completed')->sum('total');
        $approxCogs = (float) OrderItem::join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->where('orders.status', 'completed')
            ->sum(DB::raw('order_items.quantity * products.purchase_price * (1 - COALESCE(products.purchase_discount_percent, 0) / 100)'));
        $approxGrossMargin = $totalRevenue - $approxCogs;
        $approxNetProfit = $approxGrossMargin - $totalExpenses;

        $breakdownView = $request->input('view', 'yearly') === 'monthly' ? 'monthly' : 'yearly';
        $month = max(1, min(12, (int) ($request->input('month') ?: now()->month)));
        $monthly = $breakdownView === 'monthly'
            ? $this->dailyCapitalBreakdown($year, $month)
            : $this->monthlyCapitalBreakdown($year);

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

        $rows = [];
        $rows[] = [__('Total Capital Report'), $from, __('To'), $to];
        $rows[] = [];
        $rows[] = [__('Figure'), __('Amount')];
        $rows[] = [__('Total Investment'), $this->money($totalInvestment)];
        $rows[] = [__('Stock Purchased (all-time)'), $this->money($cumulativePurchaseCost)];
        $rows[] = [__('Remaining Investment'), $this->money($remainingInvestment)];
        $rows[] = [__('Current Stock Value'), $this->money($stockValue)];
        $rows[] = [__('Cash Injected'), $this->money($cashInjected)];
        $rows[] = [__('Total Out (Expenses)'), $this->money($totalExpenses)];
        $rows[] = [__('Total Liabilities'), $this->money($totalLiabilities)];
        $rows[] = [__('Total Receivables'), $this->money($totalReceivables)];
        $rows[] = [__('Net Capital'), $this->money($netCapital)];
        $rows[] = [__('Total Revenue'), $this->money($totalRevenue)];
        $rows[] = [__('Est. Cost of Goods Sold'), $this->money($approxCogs)];
        $rows[] = [__('Est. Gross Margin'), $this->money($approxGrossMargin)];
        $rows[] = [__('Est. Net Profit (after expenses)'), $this->money($approxNetProfit)];
        $rows[] = [];
        $rows[] = [$breakdownView === 'monthly' ? __('Daily Breakdown') : __('Monthly Breakdown'), $year, $breakdownView === 'monthly' ? $month : ''];
        $rows[] = [__('Period'), __('Cash In'), __('Cash Out'), __('Expenses'), __('Revenue'), __('COGS'), __('Gross Margin'), __('Net Profit')];
        foreach ($monthly as $row) {
            $rows[] = [
                $row['label'] ?? ($row['month'] ?? $row['day'] ?? ''),
                $this->money((float) $row['cash_in']),
                $this->money((float) $row['cash_out']),
                $this->money((float) $row['expenses']),
                $this->money((float) $row['revenue']),
                $this->money((float) $row['cogs']),
                $this->money((float) $row['gross_margin']),
                $this->money((float) $row['net_profit']),
            ];
        }
        $rows[] = [];
        $rows[] = [__('By Category (selected date range)')];
        $rows[] = [__('Category'), __('Cash In'), __('Cash Out')];
        foreach ($byCategory as $category => $totals) {
            $rows[] = [$category, $this->money($totals['cash_in']), $this->money($totals['cash_out'])];
        }
        $rows[] = [];
        $rows[] = [__('All Entries (selected date range)')];
        $rows[] = [__('Date'), __('Category'), __('Type'), __('Amount'), __('Note'), __('Recorded By')];
        foreach ($expenses as $expense) {
            $rows[] = [
                $expense->expense_date?->toDateString(),
                $expense->category,
                $expense->type,
                $this->money((float) $expense->amount),
                $expense->note,
                $expense->user?->name,
            ];
        }

        return $this->csvDownload($rows, 'capital-report-' . $this->fileDate() . '.csv');
    }

    // ------------------------------------------------------------------
    // Customers — mirrors ReportController::customers()
    // ------------------------------------------------------------------

    public function customers(Request $request)
    {
        Order::purgeStaleInProgress();

        $customers = Customer::withCount(['orders' => fn ($q) => $q->where('status', '!=', 'in_progress')])
            ->withSum(['orders as total_purchased' => fn ($q) => $q->where('status', 'completed')], 'total')
            ->orderByDesc('total_purchased')
            ->get();

        $walkInOrders = Order::whereNull('customer_id')->where('status', 'completed')->count();
        $walkInTotal = (float) Order::whereNull('customer_id')->where('status', 'completed')->sum('total');

        $rows = [];
        $rows[] = [__('Customer Report')];
        $rows[] = [__('Name'), __('Phone'), __('Orders'), __('Total Purchased'), __('Credit Balance')];
        $rows[] = [__('Walk-in (no account)'), '', $walkInOrders, $this->money($walkInTotal), ''];
        foreach ($customers as $customer) {
            $rows[] = [
                $customer->name,
                $customer->phone,
                $customer->orders_count,
                $this->money((float) ($customer->total_purchased ?? 0)),
                $this->money((float) $customer->credit_balance),
            ];
        }

        return $this->csvDownload($rows, 'customers-report-' . $this->fileDate() . '.csv');
    }

    // ------------------------------------------------------------------
    // Stock — mirrors ReportController::stock()
    // ------------------------------------------------------------------

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

        $rows = [];
        $rows[] = [__('Stock Report'), __('Filter'), $filter, __('Search'), $request->input('search', '')];
        $rows[] = [__('Total Stock Value (at net purchase price, after supplier discount)'), $this->money((float) $totalStockValue)];
        $rows[] = [];
        $rows[] = [__('Product'), __('Category'), __('SKU / Barcode'), __('Stock'), __('Unit'), __('Net Purchase Price'), __('Stock Value'), __('Status')];
        foreach ($products as $product) {
            $rows[] = [
                $product->name,
                $product->category,
                $product->sku,
                $product->stock,
                $product->unit,
                $this->money((float) $product->net_purchase_price),
                $this->money((float) ($product->stock * $product->net_purchase_price)),
                $product->stock <= 0 ? __('Out of Stock') : ($product->isLowStock() ? __('Low Stock') : __('Stock Available')),
            ];
        }

        return $this->csvDownload($rows, 'stock-report-' . $this->fileDate() . '.csv');
    }

    // ------------------------------------------------------------------
    // Purchases — mirrors ReportController::purchases()
    // ------------------------------------------------------------------

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

        $rows = [];
        $rows[] = [__('Purchase Report'), $from, __('To'), $to];
        $rows[] = [__('Total Purchase Value'), $this->money($totalPurchaseValue)];
        $rows[] = [__('Returned to Supplier'), $this->money($totalReturnedValue)];
        $rows[] = [__('Net Purchase Value'), $this->money($netPurchaseValue)];
        $rows[] = [__('Pending (not yet received)'), $this->money($pendingValue)];
        $rows[] = [];
        $rows[] = [__('Purchase Orders')];
        $rows[] = [__('PO #'), __('Date'), __('Supplier'), __('Status'), __('Total'), __('Product'), __('Ordered Qty'), __('Received Qty'), __('Cost Price')];
        foreach ($purchaseOrders as $po) {
            if ($po->items->isEmpty()) {
                $rows[] = [$po->id, $po->created_at?->toDateString(), $po->supplier?->name, $po->status, $this->money((float) $po->total), '', '', '', ''];
                continue;
            }
            foreach ($po->items as $item) {
                $rows[] = [
                    $po->id,
                    $po->created_at?->toDateString(),
                    $po->supplier?->name,
                    $po->status,
                    $this->money((float) $po->total),
                    $item->product?->name,
                    $item->quantity,
                    $item->received_quantity,
                    $this->money((float) $item->cost_price),
                ];
            }
        }
        $rows[] = [];
        $rows[] = [__('Returns to Suppliers')];
        $rows[] = [__('Date'), __('Product'), __('Supplier'), __('Quantity'), __('Net Value')];
        foreach ($supplierReturns as $ret) {
            $rows[] = [
                $ret->created_at?->toDateString(),
                $ret->product?->name,
                $ret->supplier?->name,
                $ret->quantity,
                $this->money((float) $ret->net_amount),
            ];
        }

        return $this->csvDownload($rows, 'purchases-report-' . $this->fileDate() . '.csv');
    }

    // ------------------------------------------------------------------
    // Cash reconciliation — mirrors ReportController::cashReconciliation()
    // ------------------------------------------------------------------

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

        $rows = [];
        $rows[] = [__('Daily Cash Reconciliation'), $date];
        $rows[] = [__('Recorded Cash Sales (system)'), $this->money((float) $recordedCash)];
        $rows[] = [__('Physical Cash Counted'), $countedCash === null ? '' : $this->money($countedCash)];
        $rows[] = [__('Difference'), $mismatch === null ? '' : $this->money($mismatch)];
        $rows[] = [__('Status'), $mismatch === null ? '' : (abs($mismatch) < 0.01 ? __('Matches') : ($mismatch > 0 ? __('Excess') : __('Shortage')))];
        $rows[] = [];
        $rows[] = [__('Cash Orders on this date')];
        $rows[] = [__('Order #'), __('Customer'), __('Total'), __('Paid Amount'), __('Due')];
        $orders = Order::where('status', 'completed')
            ->where('payment_method', 'cash')
            ->whereDate('created_at', $date)
            ->with('customer')
            ->latest()
            ->get();
        foreach ($orders as $order) {
            $rows[] = [
                $order->order_number,
                $order->customer?->name ?? __('Walk-in (no account)'),
                $this->money((float) $order->total),
                $this->money((float) $order->paid_amount),
                $this->money((float) $order->due_amount),
            ];
        }

        return $this->csvDownload($rows, 'cash-reconciliation-' . $date . '.csv');
    }

    // ------------------------------------------------------------------
    // Capital breakdown helpers — copied from ReportController so the
    // export and the page can never drift apart unnoticed. The one
    // intentional difference from the page: the page's monthly rows
    // carry a 'label'/'month' field; the export prints that label.
    // ------------------------------------------------------------------

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
                $months[$m]['expenses'] += (float) $e->amount;
            }
        });

        Order::where('status', 'completed')
            ->whereYear(DB::raw('COALESCE(original_completed_at, created_at)'), $year)
            ->with('items.product')
            ->get()
            ->each(function ($order) use (&$months) {
                $m = ($order->original_completed_at ?? $order->created_at)->month;
                $months[$m]['revenue'] += (float) $order->total;
                $order->items->each(function ($item) use (&$months, $m) {
                    if (! $item->product) {
                        return;
                    }
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
                    if (! $item->product) {
                        return;
                    }
                    $days[$d]['cogs'] += $item->quantity * $item->product->net_purchase_price;
                });
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
