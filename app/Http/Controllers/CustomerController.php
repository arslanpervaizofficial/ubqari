<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\OrderItem;
use App\Models\StockReturn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    /** Same filtering as index() — reused by destroyAll() so "Delete All"
     *  removes exactly what's currently showing, not the whole table. */
    private function filteredQuery(Request $request)
    {
        $query = Customer::query();
        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%")
                  ->orWhere('phone', 'like', "%{$request->search}%");
        }
        return $query;
    }

    public function index(Request $request)
    {
        $customers = $this->filteredQuery($request)->orderBy('name')->paginate(20)->withQueryString();
        return view('customers.index', compact('customers'));
    }

    public function create()
    {
        return view('customers.create');
    }

    /** $id excludes the record being edited from the email uniqueness
     *  check — same pattern as ProductController's sku/barcode and
     *  UserController's username/email — so updating a customer without
     *  changing their own email doesn't flag itself as a duplicate. */
    private function rules($id = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^[0-9+\-\s]{7,15}$/'],
            'email' => ['nullable', 'email', Rule::unique('customers', 'email')->ignore($id)],
            'address' => ['nullable', 'string'],
            'id_card_number' => ['nullable', 'string', 'regex:/^\d{5}-\d{7}-\d{1}$/'],
            'current_address' => ['nullable', 'string'],
            'permanent_address' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'max:2048'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ];
    }

    private function handleImageUpload(Request $request, Customer $customer = null): ?string
    {
        if (!$request->hasFile('image')) {
            return $customer->image ?? null;
        }

        $dir = public_path('uploads/customers');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Remove old image on replace
        if ($customer && $customer->image && file_exists($dir . '/' . $customer->image)) {
            @unlink($dir . '/' . $customer->image);
        }

        $filename = Str::random(20) . '.' . $request->file('image')->getClientOriginalExtension();
        $request->file('image')->move($dir, $filename);

        return $filename;
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());
        $data['image'] = $this->handleImageUpload($request);

        Customer::create($data);

        return redirect()->route('customers.index')->with('status', 'Customer added.');
    }

    public function edit(Customer $customer)
    {
        return view('customers.edit', compact('customer'));
    }

    public function update(Request $request, Customer $customer)
    {
        $data = $request->validate($this->rules($customer->id));
        $data['image'] = $this->handleImageUpload($request, $customer);

        $customer->update($data);

        return redirect()->route('customers.index')->with('status', 'Customer updated.');
    }

    public function destroy(Customer $customer)
    {
        $customer->delete();
        return back()->with('status', 'Customer moved to Trash.');
    }

    /** Bulk-delete just the checked rows — moves each to Trash (soft delete). */
    public function destroySelected(Request $request)
    {
        $ids = (array) $request->input('ids', []);
        $count = Customer::whereIn('id', $ids)->delete();
        return back()->with('status', "{$count} customer(s) moved to Trash.");
    }

    /** Deletes every customer matching the CURRENT search/filter (not the
     *  whole table) — same query index() uses to decide what's on screen. */
    public function destroyAll(Request $request)
    {
        $count = $this->filteredQuery($request)->delete();
        return back()->with('status', "{$count} customer(s) moved to Trash.");
    }

    /** Disable instead of delete — keeps their order/ledger history intact
     *  while hiding them from the POS customer picker (e.g. a party that
     *  stopped buying from us but still has an outstanding balance). */
    public function toggleActive(Customer $customer)
    {
        $customer->update(['is_active' => !$customer->is_active]);
        $label = $customer->is_active ? 'enabled' : 'disabled';
        return back()->with('status', "{$customer->name} {$label}.");
    }

    /** Ledger page: this customer's order/payment history, plus three
     *  charts scoped to just THIS customer (same idea as the Dashboard's
     *  own graphs, but for one party instead of the whole shop) — how
     *  their monthly buying has trended, what they buy most, and which of
     *  their usual products they've gone quiet on. */
    public function ledger(Customer $customer)
    {
        // A cancelled order never actually happened — nothing was sold,
        // nothing was paid, no stock moved — so it doesn't belong on a
        // customer's own statement of what they've bought. Scoping to
        // 'completed' here (rather than showing every status) is what
        // keeps a cancelled/deleted-then-restored order from showing up
        // here as if it were a real transaction.
        $orders = $customer->orders()->where('status', 'completed')->orderByDesc('created_at')->paginate(20);
        // Full (unpaginated) order history — used only by the exported/
        // shared PDF, which is meant to be a complete statement, not just
        // whatever page of 20 happens to be showing on screen right now.
        $allOrders = $customer->orders()->where('status', 'completed')->orderByDesc('created_at')->get();
        $payments = $customer->payments()->latest()->get();

        // All-time summary (not just the current page of $orders) for the
        // statement shown at the end of the exported/shared PDF.
        $ledgerSummary = [
            'total_billed' => (float) $customer->orders()->where('status', 'completed')->sum('total'),
            'total_paid' => (float) $customer->orders()->where('status', 'completed')->sum('paid_amount'),
            'total_due_from_orders' => (float) $customer->orders()->where('status', 'completed')->sum('due_amount'),
        ];

        // 1. Monthly order graph — last 12 months, this customer's own
        // completed orders only (count + total, net of nothing extra —
        // this is what THEY were billed, same as the invoice they got).
        $monthlyOrders = collect(range(11, 0))->map(function ($monthsAgo) use ($customer) {
            $date = now()->subMonths($monthsAgo);
            $scope = $customer->orders()->where('status', 'completed')
                ->whereMonth('created_at', $date->month)
                ->whereYear('created_at', $date->year);
            return [
                'label' => $date->format('M Y'),
                'count' => $scope->count(),
                'total' => (float) $scope->sum('total'),
            ];
        });

        // 2. Top selling products for THIS customer — same qty-sold-net-
        // of-returns approach as the Dashboard's Top Products, just
        // scoped down to this one customer's completed orders.
        $topProducts = OrderItem::select('product_id', DB::raw('SUM(quantity) as qty_sold'))
            ->whereHas('order', fn ($q) => $q->where('customer_id', $customer->id)->where('status', 'completed'))
            ->groupBy('product_id')
            ->with('product')
            ->get()
            ->map(function ($row) use ($customer) {
                $returned = StockReturn::where('product_id', $row->product_id)
                    ->where('customer_id', $customer->id)
                    ->where('type', 'from_customer')
                    ->sum('quantity');
                $row->qty_sold = max(0, $row->qty_sold - $returned);
                return $row;
            })
            ->sortByDesc('qty_sold')
            ->filter(fn ($row) => $row->qty_sold > 0)
            ->take(8)
            ->values();

        // 3. Products this customer USED to buy but hasn't in a while —
        // every product they've ever ordered (completed orders), sorted
        // by how long it's been since the last time, most-overdue first.
        // Meant as a reorder-reminder list for the salesperson, not a
        // stock report — a brand-new customer with no history yet just
        // sees an empty list here, which is correct.
        $lapsedProducts = OrderItem::join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.customer_id', $customer->id)
            ->where('orders.status', 'completed')
            ->groupBy('order_items.product_id')
            ->select('order_items.product_id', DB::raw('MAX(orders.created_at) as last_ordered_at'), DB::raw('SUM(order_items.quantity) as total_qty'))
            ->with('product')
            ->get()
            ->filter(fn ($row) => $row->product)
            ->sortBy('last_ordered_at')
            ->take(8)
            ->map(function ($row) {
                // Carbon 3's diffInDays() can return a signed float (not a
                // clean whole number) depending on time-of-day precision —
                // that's the "-1.08...d ago" showing up instead of a plain
                // integer. Force it through an explicit Carbon parse with
                // absolute=true, then floor it to a whole number of days.
                $row->days_since = (int) floor(\Carbon\Carbon::parse($row->last_ordered_at)->diffInDays(now(), true));
                return $row;
            })
            ->values();

        return view('customers.ledger', compact(
            'customer', 'orders', 'allOrders', 'payments', 'monthlyOrders', 'topProducts', 'lapsedProducts', 'ledgerSummary'
        ));
    }

    /** Record a standalone credit or debit against the customer's balance (no order involved).
     *  Credit = they paid something off (or a return reduced what they owe) — balance goes down.
     *  Debit  = a manual charge/adjustment — balance goes up.
     *  A credit larger than what's currently owed is allowed — it just
     *  takes the balance negative, meaning the customer has now overpaid
     *  and the business owes THEM (an advance/credit on their account),
     *  rather than being blocked as an error. */
    public function recordPayment(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'type' => ['required', 'in:credit,debit'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $customer->payments()->create([
            'type' => $data['type'],
            'amount' => $data['amount'],
            'note' => $data['note'] ?? null,
            'user_id' => auth()->id(),
        ]);

        if ($data['type'] === 'credit') {
            $customer->decrement('credit_balance', $data['amount']);
        } else {
            $customer->increment('credit_balance', $data['amount']);
        }

        $label = $data['type'] === 'credit' ? 'Payment recorded' : 'Charge added';
        $customer->refresh();
        $balanceNote = $customer->credit_balance < 0
            ? " Customer now has an advance/credit balance of Rs " . number_format(abs($customer->credit_balance), 2) . "."
            : '';
        return back()->with('status', "{$label}: Rs {$data['amount']}.{$balanceNote}");
    }

    /** Edit an existing Credit/Debit ledger entry — reverses its old effect
     *  on credit_balance first, then applies the new type/amount, so the
     *  balance never double-counts the old value while the new one is
     *  also in effect. Same "credit can exceed what's owed" allowance as
     *  recordPayment() above. */
    public function updatePayment(Request $request, Customer $customer, CustomerPayment $payment)
    {
        abort_unless((int) $payment->customer_id === (int) $customer->id, 404);

        $data = $request->validate([
            'type' => ['required', 'in:credit,debit'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($payment, $data) {
            $payment->reverseEffect();
            $payment->update($data);
            if ($data['type'] === 'credit') {
                $payment->customer->decrement('credit_balance', $data['amount']);
            } else {
                $payment->customer->increment('credit_balance', $data['amount']);
            }
        });

        return back()->with('status', 'Ledger entry updated and balance adjusted.');
    }

    /** Moves a Credit/Debit ledger entry to Trash — reverses its effect on
     *  credit_balance first (same reasoning as OrderController::destroy()),
     *  so the balance stays accurate while it's sitting in Trash. Restoring
     *  it from Trash (see TrashController) re-applies the effect. */
    public function destroyPayment(Customer $customer, CustomerPayment $payment)
    {
        abort_unless((int) $payment->customer_id === (int) $customer->id, 404);

        DB::transaction(function () use ($payment) {
            $payment->reverseEffect();
            $payment->delete();
        });

        return back()->with('status', 'Ledger entry moved to Trash and balance adjusted.');
    }
}
