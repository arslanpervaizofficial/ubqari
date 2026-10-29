<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Models\SupplierPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    /** Same filtering as index() — reused by destroyAll() so "Delete All"
     *  removes exactly what's currently showing, not the whole table. */
    private function filteredQuery(Request $request)
    {
        $query = Supplier::query();
        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%")
                  ->orWhere('phone', 'like', "%{$request->search}%");
        }
        return $query;
    }

    public function index(Request $request)
    {
        // Received PO total per supplier, in one query (not one per row),
        // so the list can show what each supplier has actually supplied.
        // The settled "Due" figure is the suppliers.balance column
        // (selected with the row by default) — received value minus
        // recorded payments/credits plus debits (see
        // Supplier::duesFigures()). The view shows both side by side.
        $suppliers = $this->filteredQuery($request)
            ->withSum(['purchaseOrders as received_po_total' => fn ($q) => $q->where('status', 'received')], 'total')
            ->orderBy('name')->paginate(20)->withQueryString();
        return view('suppliers.index', compact('suppliers'));
    }

    /** Supplier dues report for one supplier — received PO totals minus
     *  recorded supplier payments/credits plus debits. Delegates to
     *  Supplier::duesFigures() so the formula lives in one place, and
     *  ReportController reuses the stored balance for Capital
     *  Liabilities. */
    public function supplierDues(Supplier $supplier): array
    {
        return $supplier->duesFigures();
    }

    /** Supplier detail page: dues breakdown, received + pending purchase
     *  orders, and the payment/credit/debit history. The balance is
     *  re-synced on every visit (same pattern as CashManagement's ledger
     *  page calling syncBalance()) so a missed event hook can never
     *  leave a stale figure sitting here. */
    public function show(Supplier $supplier)
    {
        $dues = $supplier->syncBalance();
        $receivedOrders = $supplier->purchaseOrders()->where('status', 'received')->latest()->paginate(20);
        $pendingOrders = $supplier->purchaseOrders()->where('status', 'pending')->latest()->get();
        $payments = $supplier->payments()->with('user')->latest()->paginate(20);
        return view('suppliers.show', compact('supplier', 'dues', 'receivedOrders', 'pendingOrders', 'payments'));
    }

    /** Records a supplier ledger entry — same shape as
     *  CustomerController::recordPayment(), with the supplier's sign
     *  convention (see SupplierPayment): payment = cash paid to the
     *  supplier, credit = supplier credit note / discount granted,
     *  debit = extra charge by the supplier. Ledger row + balance sync
     *  must succeed or fail together, otherwise the history and the
     *  balance disagree permanently. Admin/manager (see routes). */
    public function recordPayment(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'type' => ['required', 'in:payment,credit,debit'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($supplier, $data) {
            $supplier->payments()->create([
                'type' => $data['type'],
                'amount' => $data['amount'],
                'note' => $data['note'] ?? null,
                'user_id' => auth()->id(),
            ]);
            $supplier->syncBalance();
        });

        $labels = ['payment' => 'Payment recorded', 'credit' => 'Credit note recorded', 'debit' => 'Debit recorded'];
        return back()->with('status', ($labels[$data['type']] ?? 'Entry recorded') . ' and supplier balance updated.');
    }

    /** Moves one supplier ledger entry to Trash (soft delete) and
     *  re-syncs the balance — mirrors
     *  CustomerController::destroyPayment() (reverseEffect + delete),
     *  except the final syncBalance() recomputes from live data, so the
     *  result is exact even if the reverse arithmetic ever drifts.
     *  Admin only (see routes). */
    public function destroyPayment(Supplier $supplier, SupplierPayment $payment)
    {
        abort_unless((int) $payment->supplier_id === (int) $supplier->id, 404);

        DB::transaction(function () use ($supplier, $payment) {
            $payment->reverseEffect();
            $payment->delete();
            $supplier->fresh()->syncBalance();
        });

        return back()->with('status', 'Supplier ledger entry moved to Trash and balance updated.');
    }

    public function create()
    {
        return view('suppliers.create');
    }

    /** Same digits-only phone normalisation as CustomerController (see
     *  there): the request value is merged normalised before validation,
     *  so the uniqueness rule compares digits-only values. A database
     *  unique index on the column is still needed (see CHANGES.txt). */
    private static function normalisePhone($phone): string
    {
        if (!is_string($phone)) {
            return '';
        }
        return preg_replace('/[^0-9]/', '', $phone) ?? '';
    }

    /** $id excludes the record being edited from the email uniqueness
     *  check — same pattern as ProductController's sku/barcode and
     *  UserController's username/email — so updating a supplier without
     *  changing their own email doesn't flag itself as a duplicate.
     *  Phone has the same treatment: the normalised (digits-only) value
     *  must be unique, so one supplier cannot split across two rows
     *  entered with different formatting. */
    private function rules($id = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^[0-9]{7,15}$/', Rule::unique('suppliers', 'phone')->ignore($id)],
            'email' => ['nullable', 'email', Rule::unique('suppliers', 'email')->ignore($id)],
            'address' => ['nullable', 'string'],
            'id_card_number' => ['nullable', 'string', 'regex:/^\d{5}-\d{7}-\d{1}$/'],
            'current_address' => ['nullable', 'string'],
            'permanent_address' => ['nullable', 'string'],
            'image' => ['nullable', 'image', 'max:2048'],
        ];
    }

    private function handleImageUpload(Request $request, Supplier $supplier = null): ?string
    {
        if (!$request->hasFile('image')) {
            return $supplier->image ?? null;
        }

        $dir = public_path('uploads/suppliers');
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if ($supplier && $supplier->image && file_exists($dir . '/' . $supplier->image)) {
            @unlink($dir . '/' . $supplier->image);
        }

        $filename = Str::random(20) . '.' . $request->file('image')->getClientOriginalExtension();
        $request->file('image')->move($dir, $filename);

        return $filename;
    }

    public function store(Request $request)
    {
        $request->merge(['phone' => self::normalisePhone($request->input('phone'))]);
        $data = $request->validate($this->rules());
        $data['image'] = $this->handleImageUpload($request);

        Supplier::create($data);

        return redirect()->route('suppliers.index')->with('status', 'Supplier added.');
    }

    public function edit(Supplier $supplier)
    {
        return view('suppliers.edit', compact('supplier'));
    }

    public function update(Request $request, Supplier $supplier)
    {
        $request->merge(['phone' => self::normalisePhone($request->input('phone'))]);
        $data = $request->validate($this->rules($supplier->id));
        $data['image'] = $this->handleImageUpload($request, $supplier);

        $supplier->update($data);

        return redirect()->route('suppliers.index')->with('status', 'Supplier updated.');
    }

    /** Why a supplier cannot be deleted while money is outstanding —
     *  mirrors the customer delete guard (v2, H13): deleting a supplier
     *  with a non-zero balance would make what is owed (or an advance
     *  paid to them) simply vanish from Capital Liabilities, while their
     *  purchase orders still exist. Settle the balance first, or use
     *  Disable instead, which keeps history intact. */
    private function deleteBlockedReason(Supplier $supplier): ?string
    {
        $supplier->syncBalance();
        if (round((float) $supplier->fresh()->balance, 2) != 0.0) {
            return "Cannot delete {$supplier->name}: their balance is " . number_format((float) $supplier->balance, 2) . " — settle it first (record a payment on their detail page) or Disable them instead, which keeps the history.";
        }
        return null;
    }

    /** Deletes the given suppliers one by one, skipping (not deleting)
     *  any with an outstanding balance, and reports both counts. */
    private function deleteWithBalanceGuard($suppliers): array
    {
        $deleted = 0;
        $blocked = [];
        foreach ($suppliers as $supplier) {
            if ($this->deleteBlockedReason($supplier) !== null) {
                $blocked[] = $supplier->name;
                continue;
            }
            $supplier->delete();
            $deleted++;
        }
        return [$deleted, $blocked];
    }

    public function destroy(Supplier $supplier)
    {
        if (($reason = $this->deleteBlockedReason($supplier)) !== null) {
            return back()->withErrors(['supplier' => $reason]);
        }
        $supplier->delete();
        return back()->with('status', 'Supplier moved to Trash.');
    }

    /** Bulk-delete just the checked rows — moves each to Trash (soft delete),
     *  except suppliers with an outstanding balance, which are skipped
     *  and named in the message. */
    public function destroySelected(Request $request)
    {
        $ids = (array) $request->input('ids', []);
        [$deleted, $blocked] = $this->deleteWithBalanceGuard(Supplier::whereIn('id', $ids)->get());
        $message = "{$deleted} supplier(s) moved to Trash.";
        if ($blocked) {
            $message .= ' Skipped (outstanding balance): ' . implode(', ', $blocked) . '.';
        }
        return back()->with('status', $message);
    }

    /** Deletes every supplier matching the CURRENT search/filter (not the
     *  whole table) — same query index() uses to decide what's on screen.
     *  Suppliers with an outstanding balance are skipped and named. */
    public function destroyAll(Request $request)
    {
        [$deleted, $blocked] = $this->deleteWithBalanceGuard($this->filteredQuery($request)->get());
        $message = "{$deleted} supplier(s) moved to Trash.";
        if ($blocked) {
            $message .= ' Skipped (outstanding balance): ' . implode(', ', $blocked) . '.';
        }
        return back()->with('status', $message);
    }

    /** Disable instead of delete — keeps past purchase-order history intact
     *  while hiding them from the "New Purchase Order" supplier picker. */
    public function toggleActive(Supplier $supplier)
    {
        $supplier->update(['is_active' => !$supplier->is_active]);
        $label = $supplier->is_active ? 'enabled' : 'disabled';
        return back()->with('status', "{$supplier->name} {$label}.");
    }
}
