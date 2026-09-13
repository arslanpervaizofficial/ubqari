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
        $suppliers = $this->filteredQuery($request)->orderBy('name')->paginate(20)->withQueryString();
        return view('suppliers.index', compact('suppliers'));
    }

    public function create()
    {
        return view('suppliers.create');
    }

    /** $id excludes the record being edited from the email uniqueness
     *  check — same pattern as ProductController's sku/barcode and
     *  UserController's username/email — so updating a supplier without
     *  changing their own email doesn't flag itself as a duplicate. */
    private function rules($id = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^[0-9+\-\s]{7,15}$/'],
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
