<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\Request;
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
        // This is received value before any supplier payments — there is
        // no supplier_payments table yet, so it is NOT a settled "due"
        // figure on its own (see Supplier::duesFigures() and CHANGES.txt).
        $suppliers = $this->filteredQuery($request)
            ->withSum(['purchaseOrders as received_po_total' => fn ($q) => $q->where('status', 'received')], 'total')
            ->orderBy('name')->paginate(20)->withQueryString();
        return view('suppliers.index', compact('suppliers'));
    }

    /** Supplier dues report for one supplier — received PO totals minus
     *  recorded supplier payments (currently always 0: no
     *  supplier_payments table exists yet). Delegates to
     *  Supplier::duesFigures() so the formula lives in one place, and so
     *  ReportController can reuse it when supplier dues are added to
     *  Capital Liabilities (see CHANGES.txt). */
    public function supplierDues(Supplier $supplier): array
    {
        return $supplier->duesFigures();
    }

    /** Supplier detail page: received + pending purchase orders with the
     *  dues figures above. NOTE: no route for this exists yet — the
     *  routes owner must add one GET route, e.g.
     *  Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
     *  (inside the existing admin/manager group, next to the other
     *  supplier routes). Until then this method and its view are shipped
     *  but unreachable, and dues remain visible on the suppliers list. */
    public function show(Supplier $supplier)
    {
        $dues = $this->supplierDues($supplier);
        $receivedOrders = $supplier->purchaseOrders()->where('status', 'received')->latest()->paginate(20);
        $pendingOrders = $supplier->purchaseOrders()->where('status', 'pending')->latest()->get();
        return view('suppliers.show', compact('supplier', 'dues', 'receivedOrders', 'pendingOrders'));
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

    public function destroy(Supplier $supplier)
    {
        $supplier->delete();
        return back()->with('status', 'Supplier moved to Trash.');
    }

    /** Bulk-delete just the checked rows — moves each to Trash (soft delete). */
    public function destroySelected(Request $request)
    {
        $ids = (array) $request->input('ids', []);
        $count = Supplier::whereIn('id', $ids)->delete();
        return back()->with('status', "{$count} supplier(s) moved to Trash.");
    }

    /** Deletes every supplier matching the CURRENT search/filter (not the
     *  whole table) — same query index() uses to decide what's on screen. */
    public function destroyAll(Request $request)
    {
        $count = $this->filteredQuery($request)->delete();
        return back()->with('status', "{$count} supplier(s) moved to Trash.");
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
