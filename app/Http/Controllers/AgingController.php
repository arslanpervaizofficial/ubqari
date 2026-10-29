<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use Carbon\Carbon;

/**
 * FEATURE 3 — Customer Aging (CUSTOMER, not supplier).
 *
 * Read-only report: this controller writes nothing. In particular it does
 * NOT call Customer::syncBalance() (that would rewrite credit_balance when
 * it drifts) and does NOT call Order::purgeStaleInProgress() (that deletes /
 * demotes stale carts) — both would mutate data, and the whole point of
 * this page is to show the stored figures side by side, differences included.
 *
 * Scope (exactly as specified):
 *  - Only orders with status = 'completed' and due_amount > 0 are aged.
 *    Held / in-progress / cancelled orders are not dues, and an order with
 *    a zero or negative due_amount (overpaid, i.e. an advance) is not an
 *    open due either — see NOTES.txt for why that matters to reconciliation.
 *  - Soft-deleted orders and customers are excluded automatically by
 *    Eloquent's SoftDeletes global scope, matching every other report.
 *  - Order date = original_completed_at when set, otherwise created_at.
 *    Age = today (Asia/Karachi) minus that order date, in whole calendar
 *    days, measured on Karachi calendar dates (timestamps are stored in
 *    UTC — the app's config timezone is UTC — so both dates are converted
 *    to Asia/Karachi before subtracting; a plain UTC subtraction could put
 *    a late-evening Karachi order in the wrong bucket).
 *  - Buckets: 0-30 days, 31-60 days, 61-90 days, 90+ days. To keep the
 *    buckets disjoint (so they always sum to the customer total), "90+"
 *    means strictly over 90 days: an order exactly 90 days old sits in
 *    61-90. The view footnotes this.
 *  - A future-dated order (possible via the order change-date flow) would
 *    give a negative age; it is clamped to 0 days, i.e. the 0-30 bucket,
 *    rather than falling through every bucket.
 */
class AgingController extends Controller
{
    /** Bucket keys, in display order. */
    public const BUCKETS = ['bucket_0_30', 'bucket_31_60', 'bucket_61_90', 'bucket_90_plus'];

    /** Human labels for the bucket keys above. */
    public const BUCKET_LABELS = [
        'bucket_0_30' => '0-30 days',
        'bucket_31_60' => '31-60 days',
        'bucket_61_90' => '61-90 days',
        'bucket_90_plus' => '90+ days',
    ];

    /** Tolerance for treating two decimal(12,2) money figures as equal. */
    public const DIFFERENCE_TOLERANCE = 0.005;

    /** "Today" for aging, as a Karachi calendar date at midnight. */
    protected function today(): Carbon
    {
        return Carbon::today('Asia/Karachi');
    }

    /** The order's date as a Karachi calendar date at midnight. */
    protected function orderDate(Order $order): Carbon
    {
        return ($order->original_completed_at ?? $order->created_at)
            ->copy()
            ->timezone('Asia/Karachi')
            ->startOfDay();
    }

    /** Whole days from the order date to today (Karachi); future dates clamp to 0. */
    protected function ageInDays(Order $order, Carbon $today): int
    {
        return max(0, (int) $this->orderDate($order)->diffInDays($today, false));
    }

    /** Which bucket an age in days falls into (see class docblock re day 90). */
    public static function bucketForAge(int $age): string
    {
        if ($age <= 30) {
            return 'bucket_0_30';
        }
        if ($age <= 60) {
            return 'bucket_31_60';
        }
        if ($age <= 90) {
            return 'bucket_61_90';
        }
        return 'bucket_90_plus';
    }

    /** Zeroed bucket array, so every row always carries all four buckets. */
    public static function emptyBuckets(): array
    {
        return [
            'bucket_0_30' => 0.0,
            'bucket_31_60' => 0.0,
            'bucket_61_90' => 0.0,
            'bucket_90_plus' => 0.0,
        ];
    }

    /** Base query for "open dues": completed orders with a positive due. */
    protected function openDuesQuery()
    {
        return Order::where('status', 'completed')->where('due_amount', '>', 0);
    }

    /**
     * Customers that belong on this report: named customers who either
     * have at least one open due order, or carry a non-zero stored
     * credit_balance with no open order (e.g. manual ledger debits only).
     * The second group matters for reconciliation: without them, a balance
     * that exists only in the ledger would be invisible here. Fully settled
     * customers (no open dues, zero balance) have nothing to age and are
     * left off, keeping the list to customers with something outstanding.
     */
    protected function reportCustomersQuery()
    {
        return Customer::where(function ($q) {
            $q->whereHas('orders', function ($q) {
                $q->where('status', 'completed')->where('due_amount', '>', 0);
            })->orWhere('credit_balance', '!=', 0);
        });
    }

    /**
     * Main aging list, 20 customers per page.
     *
     * Pagination covers only the customer rows. The grand-total row and
     * the walk-in line are computed over ALL qualifying rows (chunked, so
     * a large order history is never loaded into memory at once), never
     * just over the current page.
     */
    public function index()
    {
        $today = $this->today();

        $customers = $this->reportCustomersQuery()
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        // Bucket the open dues of just the customers on this page (one
        // query, no N+1). The bucket sums are the single source of truth
        // for each row's total — the row total is the sum of its buckets.
        $rows = [];
        foreach ($customers as $customer) {
            $rows[$customer->id] = [
                'buckets' => self::emptyBuckets(),
                'total' => 0.0,
                'orders_count' => 0,
            ];
        }

        if ($customers->count() > 0) {
            $this->openDuesQuery()
                ->whereIn('customer_id', $customers->getCollection()->pluck('id'))
                ->get(['customer_id', 'due_amount', 'original_completed_at', 'created_at'])
                ->each(function (Order $order) use ($today, &$rows) {
                    $bucket = self::bucketForAge($this->ageInDays($order, $today));
                    $amount = round((float) $order->due_amount, 2);
                    $rows[$order->customer_id]['buckets'][$bucket] = round(
                        $rows[$order->customer_id]['buckets'][$bucket] + $amount, 2
                    );
                    $rows[$order->customer_id]['total'] = round(
                        $rows[$order->customer_id]['total'] + $amount, 2
                    );
                    $rows[$order->customer_id]['orders_count']++;
                });
        }

        // Difference per page row: open dues (from orders) minus the stored
        // credit_balance. Flagged when it exceeds half a cent; the flag is
        // informational only — see the view note for the known causes.
        $differences = [];
        foreach ($customers as $customer) {
            $differences[$customer->id] = round(
                $rows[$customer->id]['total'] - (float) $customer->credit_balance, 2
            );
        }

        // Grand totals over ALL named customers (not just this page),
        // accumulated in chunks so memory stays flat on large histories.
        $grandBuckets = self::emptyBuckets();
        $grandTotal = 0.0;
        $grandOrdersCount = 0;
        $this->openDuesQuery()
            ->whereNotNull('customer_id')
            ->select(['due_amount', 'original_completed_at', 'created_at'])
            ->orderBy('id')
            ->chunk(1000, function ($orders) use ($today, &$grandBuckets, &$grandTotal, &$grandOrdersCount) {
                foreach ($orders as $order) {
                    $bucket = self::bucketForAge($this->ageInDays($order, $today));
                    $amount = round((float) $order->due_amount, 2);
                    $grandBuckets[$bucket] = round($grandBuckets[$bucket] + $amount, 2);
                    $grandTotal = round($grandTotal + $amount, 2);
                    $grandOrdersCount++;
                }
            });

        // Stored credit_balance summed over the same customer set as the
        // rows, so the grand difference is like-for-like with the rows.
        $grandBalance = round((float) $this->reportCustomersQuery()->sum('credit_balance'), 2);
        $grandDifference = round($grandTotal - $grandBalance, 2);

        // Walk-in dues: completed walk-in orders (customer_id null) with a
        // positive due. No customer row exists for them, so they get one
        // bucketed total line of their own — NOT folded into the grand
        // total above, which covers named customers only.
        $walkInBuckets = self::emptyBuckets();
        $walkInTotal = 0.0;
        $walkInOrdersCount = 0;
        $this->openDuesQuery()
            ->whereNull('customer_id')
            ->select(['due_amount', 'original_completed_at', 'created_at'])
            ->orderBy('id')
            ->chunk(1000, function ($orders) use ($today, &$walkInBuckets, &$walkInTotal, &$walkInOrdersCount) {
                foreach ($orders as $order) {
                    $bucket = self::bucketForAge($this->ageInDays($order, $today));
                    $amount = round((float) $order->due_amount, 2);
                    $walkInBuckets[$bucket] = round($walkInBuckets[$bucket] + $amount, 2);
                    $walkInTotal = round($walkInTotal + $amount, 2);
                    $walkInOrdersCount++;
                }
            });

        return view('aging.index', [
            'customers' => $customers,
            'rows' => $rows,
            'differences' => $differences,
            'grandBuckets' => $grandBuckets,
            'grandTotal' => $grandTotal,
            'grandOrdersCount' => $grandOrdersCount,
            'grandBalance' => $grandBalance,
            'grandDifference' => $grandDifference,
            'walkInBuckets' => $walkInBuckets,
            'walkInTotal' => $walkInTotal,
            'walkInOrdersCount' => $walkInOrdersCount,
            'asOf' => $today->format('Y-m-d'),
        ]);
    }

    /**
     * Per-customer drilldown: every open due order for one customer, with
     * its order date, age in days and bucket — the detail behind that
     * customer's row on the main list. Read-only, like the list.
     */
    public function show(Customer $customer)
    {
        $today = $this->today();

        $orders = $customer->orders()
            ->where('status', 'completed')
            ->where('due_amount', '>', 0)
            ->orderByRaw('COALESCE(original_completed_at, created_at) DESC')
            ->paginate(20)
            ->withQueryString();

        // Per-order aging for the orders on this page (parallel map keyed
        // by order id, so the view never has to recompute dates).
        $aging = [];
        foreach ($orders as $order) {
            $age = $this->ageInDays($order, $today);
            $aging[$order->id] = [
                'date' => $this->orderDate($order)->format('Y-m-d'),
                'age' => $age,
                'bucket' => self::bucketForAge($age),
                'bucket_label' => self::BUCKET_LABELS[self::bucketForAge($age)],
            ];
        }

        // Buckets over ALL of this customer's open dues, not just the page.
        $buckets = self::emptyBuckets();
        $total = 0.0;
        $customer->orders()
            ->where('status', 'completed')
            ->where('due_amount', '>', 0)
            ->get(['due_amount', 'original_completed_at', 'created_at'])
            ->each(function (Order $order) use ($today, &$buckets, &$total) {
                $bucket = self::bucketForAge($this->ageInDays($order, $today));
                $amount = round((float) $order->due_amount, 2);
                $buckets[$bucket] = round($buckets[$bucket] + $amount, 2);
                $total = round($total + $amount, 2);
            });

        $difference = round($total - (float) $customer->credit_balance, 2);

        return view('aging.show', [
            'customer' => $customer,
            'orders' => $orders,
            'aging' => $aging,
            'buckets' => $buckets,
            'total' => $total,
            'difference' => $difference,
            'asOf' => $today->format('Y-m-d'),
        ]);
    }
}
