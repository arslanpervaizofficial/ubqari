<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerPayment;
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

    public function ledger(Customer $customer)
    {
        $orders = $customer->orders()->orderByDesc('created_at')->paginate(20);
        $payments = $customer->payments()->latest()->get();
        return view('customers.ledger', compact('customer', 'orders', 'payments'));
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
        abort_unless($payment->customer_id === $customer->id, 404);

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
        abort_unless($payment->customer_id === $customer->id, 404);

        DB::transaction(function () use ($payment) {
            $payment->reverseEffect();
            $payment->delete();
        });

        return back()->with('status', 'Ledger entry moved to Trash and balance adjusted.');
    }
}
