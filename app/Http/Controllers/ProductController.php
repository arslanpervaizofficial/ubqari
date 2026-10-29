<?php

namespace App\Http\Controllers;

use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Support\SimpleXlsx;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    /** Same filtering as index() — reused by destroyAll()/reactivateAll() so
     *  those bulk actions act on exactly what's currently on screen. */
    private function filteredQuery(Request $request)
    {
        $showInactive = $request->boolean('inactive');
        $query = Product::query()->where('is_active', !$showInactive);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        return $query;
    }

    public function index(Request $request)
    {
        $showInactive = $request->boolean('inactive');
        $products = $this->filteredQuery($request)->orderBy('name')->paginate(20)->withQueryString();
        $categories = Product::whereNotNull('category')->where('category', '!=', '')->distinct()->pluck('category');
        $inactiveCount = Product::where('is_active', false)->count();

        return view('products.index', compact('products', 'categories', 'showInactive', 'inactiveCount'));
    }

    public function create()
    {
        $categories = Product::whereNotNull('category')->where('category', '!=', '')->distinct()->orderBy('category')->pluck('category');
        return view('products.create', compact('categories'));
    }

    private function rules($id = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['required', 'string'],
            'barcode' => ['nullable', 'string'],
            'category' => ['nullable', 'string'],
            'new_category' => ['nullable', 'string'],
            'unit' => ['required', 'string'],
            'purchase_price' => ['required', 'numeric', 'min:0'],
            'purchase_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sale_price' => ['required', 'numeric', 'min:0'],
            'max_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'stock' => ['required', 'numeric', 'min:0'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'max_stock' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function store(Request $request)
    {
        // If this SKU belongs to a previously-disabled product, don't just
        // fail validation — offer to reactivate it instead of creating a duplicate.
        if ($request->filled('sku')) {
            $disabled = Product::where('sku', $request->sku)->where('is_active', false)->first();
            if ($disabled) {
                return back()->withInput()->withErrors([
                    'sku' => "SKU \"{$request->sku}\" belongs to a disabled product (\"{$disabled->name}\"). Reactivate it instead of creating a new one.",
                ])->with('reactivate_candidate', $disabled);
            }
        }

        $data = $request->validate(array_merge($this->rules(), [
            'sku' => ['required', 'string', 'unique:products,sku'],
            'barcode' => ['nullable', 'string', 'unique:products,barcode'],
        ]));

        // If they typed a new category instead of picking one, use that.
        if (!empty($data['new_category'])) {
            $data['category'] = $data['new_category'];
        }
        unset($data['new_category']);

        $product = Product::create($data);

        // M7 — opening price at creation, so the price-history page
        // shows where this product's sale price started.
        ProductPriceHistory::create([
            'product_id' => $product->id,
            'old_price' => 0,
            'new_price' => $product->sale_price,
            'changed_by' => auth()->id(),
        ]);

        return redirect()->route('products.index')->with('status', 'Product added.');
    }

    public function edit(Product $product)
    {
        $categories = Product::whereNotNull('category')->where('category', '!=', '')->distinct()->orderBy('category')->pluck('category');
        return view('products.edit', compact('product', 'categories'));
    }

    public function update(Request $request, Product $product)
    {
        // H10 — stock is validated (so a negative value is still
        // rejected) but never mass-assigned here: it is removed from
        // the updatable data, and a submitted change is routed through
        // a logged StockMovement adjustment instead, so the stock
        // figure always reconciles to its movement history with a
        // reason and a user attached. The form field still exists for
        // create() (opening stock) and for edit it now means "set stock
        // to this value, logged as an adjustment".
        $rules = $this->rules($product->id);
        unset($rules['stock']);
        $data = $request->validate(array_merge($rules, [
            'sku' => ['required', 'string', 'unique:products,sku,' . $product->id],
            'barcode' => ['nullable', 'string', 'unique:products,barcode,' . $product->id],
            'stock' => ['nullable', 'numeric', 'min:0'],
        ]));
        $submittedStock = $data['stock'] ?? null;
        unset($data['stock']);

        if (!empty($data['new_category'])) {
            $data['category'] = $data['new_category'];
        }
        unset($data['new_category']);

        if ((float) $product->sale_price !== (float) $data['sale_price']) {
            ProductPriceHistory::create([
                'product_id' => $product->id,
                'old_price' => $product->sale_price,
                'new_price' => $data['sale_price'],
                'changed_by' => auth()->id(),
            ]);
        }

        $product->update($data);

        if ($submittedStock !== null && $submittedStock !== '' && (float) $submittedStock !== (float) $product->fresh()->stock) {
            $oldStock = (float) $product->fresh()->stock;
            $delta = (float) $submittedStock - $oldStock;
            StockMovement::create([
                'product_id' => $product->id,
                'type' => 'adjustment',
                'quantity' => $delta,
                'reason' => "Manual stock correction from product form ({$oldStock} → {$submittedStock})",
                'user_id' => auth()->id(),
            ]);
            $product->increment('stock', $delta);
        }

        return redirect()->route('products.index')->with('status', 'Product updated.');
    }

    /** M8 — why a product may NOT be deactivated right now, or null when
     *  it may. Deactivation used to be a bare is_active=false even with
     *  stock on hand, a live cart holding the product, or a pending PO
     *  for it — and the product then kept moving through held carts
     *  and PO receiving while hidden from billing and the PO picker. */
    private function deactivationBlockReason(Product $product): ?string
    {
        if ((float) $product->stock > 0) {
            return "\"{$product->name}\" disable nahi ho sakta: iski {$product->stock} stock maujood hai. Pehle stock khatam karein ya adjust karein.";
        }
        // v2.2 (R11): name the blocking carts — an abandoned held cart
        // used to block deactivation forever with no way to find it.
        $blockingOrders = OrderItem::where('product_id', $product->id)
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['in_progress', 'hold']))
            ->with('order')
            ->get()
            ->map(fn ($i) => $i->order?->order_number)
            ->filter()
            ->unique()
            ->values();
        if ($blockingOrders->isNotEmpty()) {
            $shown = $blockingOrders->take(5)->implode(', ');
            $more = $blockingOrders->count() > 5 ? ' (aur ' . ($blockingOrders->count() - 5) . ' aur)' : '';
            return "\"{$product->name}\" disable nahi ho sakta: ye product in khuli/held carts me maujood hai: {$shown}{$more}. Pehle wo cart complete ya cancel karein.";
        }
        if (PurchaseOrderItem::where('product_id', $product->id)
            ->whereHas('purchaseOrder', fn ($q) => $q->where('status', 'pending'))
            ->exists()) {
            return "\"{$product->name}\" disable nahi ho sakta: is product ka aik pending purchase order maujood hai. Pehle wo PO receive ya delete karein.";
        }
        return null;
    }

    /** Soft-disable instead of hard delete — keeps historical order/ledger data intact. */
    public function destroy(Product $product)
    {
        if ($reason = $this->deactivationBlockReason($product)) {
            return back()->withErrors(['error' => $reason]);
        }
        $product->update(['is_active' => false]);
        return back()->with('status', "{$product->name} disabled and hidden from Products/Billing. You can reactivate it anytime from the Disabled filter.");
    }

    /** Bulk-disable the checked rows (same effect as destroy(), just for many at once).
     *  Blocked rows are skipped with their reasons reported, not silently disabled. */
    public function destroySelected(Request $request)
    {
        $ids = (array) $request->input('ids', []);
        $count = 0;
        $blocked = [];
        foreach (Product::whereIn('id', $ids)->get() as $product) {
            if ($reason = $this->deactivationBlockReason($product)) {
                $blocked[] = $product->name;
                continue;
            }
            $product->update(['is_active' => false]);
            $count++;
        }
        $msg = "{$count} product(s) disabled. You can reactivate them anytime from the Disabled filter.";
        if ($blocked) {
            $msg .= ' Blocked (stock/cart/pending PO): ' . implode(', ', $blocked) . '.';
        }
        return back()->with('status', $msg);
    }

    /** Disables every product matching the CURRENT search/category filter.
     *  Blocked rows are skipped with their reasons reported. */
    public function destroyAll(Request $request)
    {
        $count = 0;
        $blocked = [];
        foreach ($this->filteredQuery($request)->get() as $product) {
            if ($reason = $this->deactivationBlockReason($product)) {
                $blocked[] = $product->name;
                continue;
            }
            $product->update(['is_active' => false]);
            $count++;
        }
        $msg = "{$count} product(s) disabled. You can reactivate them anytime from the Disabled filter.";
        if ($blocked) {
            $msg .= ' Blocked (stock/cart/pending PO): ' . implode(', ', $blocked) . '.';
        }
        return back()->with('status', $msg);
    }

    public function reactivate(Product $product)
    {
        $product->update(['is_active' => true]);
        return back()->with('status', "{$product->name} reactivated.");
    }

    /** Bulk-reactivate the checked rows on the Disabled filter. */
    public function reactivateSelected(Request $request)
    {
        $ids = (array) $request->input('ids', []);
        $count = Product::whereIn('id', $ids)->update(['is_active' => true]);
        return back()->with('status', "{$count} product(s) reactivated.");
    }

    /** Reactivates every product matching the current filter (only meaningful
     *  on the Disabled view, but harmless either way). */
    public function reactivateAll(Request $request)
    {
        $count = $this->filteredQuery($request)->update(['is_active' => true]);
        return back()->with('status', "{$count} product(s) reactivated.");
    }

    /** Permanently deletes one already-disabled product. Only works if it
     *  has no sales/purchase/stock-movement history — the database refuses
     *  (foreign key) to delete a product that's ever been sold or purchased. */
    public function forceDeleteOne(Product $product)
    {
        try {
            $product->delete();
            return back()->with('status', "{$product->name} permanently deleted.");
        } catch (\Illuminate\Database\QueryException $e) {
            return back()->withErrors(['error' => "Can't permanently delete {$product->name} — it has sales, purchase, or stock history attached."]);
        }
    }

    /** Bulk version of forceDeleteOne() for the checked rows — one blocked
     *  (still-referenced) product doesn't stop the rest of the batch. */
    public function forceDeleteSelected(Request $request)
    {
        $ids = (array) $request->input('ids', []);
        return back()->with('status', $this->forceDeleteEach(Product::whereIn('id', $ids)->get()));
    }

    public function forceDeleteAll(Request $request)
    {
        return back()->with('status', $this->forceDeleteEach($this->filteredQuery($request)->get()));
    }

    private function forceDeleteEach($products): string
    {
        $ok = 0;
        $blocked = 0;
        foreach ($products as $product) {
            try {
                $product->delete(); // Product has no SoftDeletes — this is already a real delete
                $ok++;
            } catch (\Illuminate\Database\QueryException $e) {
                $blocked++;
            }
        }

        $msg = "{$ok} product(s) permanently deleted.";
        if ($blocked) {
            $msg .= " {$blocked} couldn't be deleted because they have sales, purchase, or stock history attached — they're still in the Disabled list.";
        }
        return $msg;
    }

    public function priceHistory(Product $product)
    {
        $history = $product->priceHistory()->with('changedBy')->latest()->get();
        return view('products.price_history', compact('product', 'history'));
    }

    /** Lightweight AJAX search for the "type 2-3 letters" product pickers
     *  (Purchase Order line items etc.) — avoids shipping/scrolling the full
     *  product list and avoids a page reload. */
    public function ajaxSearch(Request $request)
    {
        $q = trim((string) $request->input('q', ''));
        $query = Product::active()->orderBy('name');

        if (strlen($q) >= 2) {
            $query->where(function ($qq) use ($q) {
                $qq->where('name', 'like', "%{$q}%")
                   ->orWhere('sku', 'like', "%{$q}%")
                   ->orWhere('barcode', 'like', "%{$q}%");
            });
        }

        $products = $query->limit(20)->get(['id', 'name', 'sku', 'purchase_price', 'purchase_discount_percent', 'sale_price', 'max_discount_percent', 'stock', 'unit']);

        return response()->json($products->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'sku' => $p->sku,
            'cost' => $p->purchase_price,
            'discount' => $p->purchase_discount_percent,
            // Added for the stock-return forms (customer returns default to
            // sale price, not purchase price) — existing callers that only
            // read 'cost'/'discount' (Purchase Order form) are unaffected.
            'price' => $p->sale_price,
            'max_discount' => $p->max_discount_percent,
            'stock' => $p->stock,
            'unit' => $p->unit,
        ]));
    }

    public function generateBarcode()
    {
        return response()->json(['barcode' => $this->generateUniqueBarcode()]);
    }

    private function generateUniqueBarcode(): string
    {
        do {
            $barcode = (string) random_int(1000000000000, 9999999999999);
        } while (Product::where('barcode', $barcode)->exists());

        return $barcode;
    }

    /** The column order for both export() and import() — keep these two in
     *  lockstep, since a file exported here is meant to be re-uploaded
     *  through import() unchanged (round-trip), and matches the layout of
     *  a typical supplier/company price list (Index, Category, Product
     *  Name, Rate, Available Stock, Min Stock, Max Stock, Discount, Max
     *  Discount). "Discount" (index 7) is the Purchase Discount % we get
     *  from the supplier; "Max Discount" (index 8) is the separate billing
     *  ceiling — the most we're willing to discount this product to a
     *  customer. */
    private const IMPORT_HEADERS = ['Index', 'Category', 'Product Name', 'Rate', 'Available Stock', 'Min Stock', 'Max Stock', 'Discount', 'Max Discount'];

    /** Downloads every product (active and disabled) as a .xlsx price list
     *  in the exact layout import() expects — round-trips cleanly if you
     *  export, edit in Excel, then re-import. */
    public function export()
    {
        $products = Product::orderBy('category')->orderBy('name')->get();

        $rows = [self::IMPORT_HEADERS];
        foreach ($products as $i => $p) {
            $rows[] = [
                $i + 1,
                $p->category,
                $p->name,
                $p->purchase_price,
                $p->stock,
                $p->min_stock,
                $p->max_stock,
                $p->purchase_discount_percent,
                $p->max_discount_percent,
            ];
        }

        $filename = 'products-' . now()->format('Y-m-d') . '.xlsx';

        return response(SimpleXlsx::write($rows), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /** Upserts products from a price-list .xlsx in the same layout as
     *  export() — matched by Product Name (case-insensitive, trimmed).
     *  Matched products get Category/Rate/Min/Max/Discount updated;
     *  unmatched rows create a new product instead. Nothing is ever
     *  deleted here, and no product is skipped just for being "different"
     *  from the sheet — every row either updates its match or creates a
     *  new product, so re-running the same file twice is always safe.
     *
     *  H11 — a matched product's Sale Price is NEVER touched here: the
     *  sheet has no sale-price column, and the old code set
     *  sale_price = purchase_price on every row, silently destroying
     *  every selling margin on every re-import. Only a NEW product gets
     *  sale_price = the sheet's Rate (as its opening price).
     *  H10 — a matched product's stock is NEVER overwritten absolutely:
     *  the sheet value is applied as a logged StockMovement adjustment
     *  (new minus old), so stock still reconciles to its movement
     *  history. M8 — if the resulting stock would exceed max_stock
     *  (the sheet's value when given, else the stored one), v2.2 (R5)
     *  skips just that row and reports it — the rest of the import
     *  still applies. v2.0 rejected the WHOLE import for one stale
     *  cap, blocking unrelated products. */
    public function import(Request $request)
    {
        $request->validate(['file' => ['required', 'file']]);

        try {
            $rows = SimpleXlsx::read($request->file('file')->getRealPath());
        } catch (\Throwable $e) {
            return back()->withErrors(['file' => 'Could not read that file: ' . $e->getMessage()]);
        }

        // First row is the header — drop it unconditionally, whatever it
        // actually says, since column ORDER (not header text) is what
        // matters here.
        array_shift($rows);

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $blocked = [];

        try {
            DB::transaction(function () use ($rows, &$created, &$updated, &$skipped, &$blocked) {
                foreach ($rows as $row) {
                    $name = trim((string) ($row[2] ?? ''));
                    if ($name === '') {
                        $skipped++;
                        continue;
                    }

                    $sheetStock = is_numeric($row[4] ?? null) ? (float) $row[4] : 0;
                    $sheetMaxStock = is_numeric($row[6] ?? null) ? (float) $row[6] : null;
                    $attributes = [
                        'category' => trim((string) ($row[1] ?? '')) ?: null,
                        'purchase_price' => is_numeric($row[3] ?? null) ? (float) $row[3] : 0,
                        'min_stock' => is_numeric($row[5] ?? null) ? (float) $row[5] : null,
                        'max_stock' => $sheetMaxStock,
                        'purchase_discount_percent' => is_numeric($row[7] ?? null) ? (float) $row[7] : 0,
                        'max_discount_percent' => is_numeric($row[8] ?? null) ? (float) $row[8] : 0,
                    ];

                    $product = Product::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

                    if ($product) {
                        // M8 — resulting stock (the sheet value, since the
                        // adjustment brings stock exactly to it) must not
                        // exceed max_stock when one is set.
                        // v2.2 (R5): skip-and-report just this row — the
                        // check runs BEFORE any write for this row, so a
                        // skipped row leaves nothing half-applied, and
                        // the rest of the import still commits.
                        $effectiveMax = $sheetMaxStock ?? $product->max_stock;
                        if ($effectiveMax !== null && $sheetStock > (float) $effectiveMax) {
                            $blocked[] = "\"{$name}\" ki stock sheet ke mutabiq {$sheetStock} ho jaye gi, jo max stock ({$effectiveMax}) se zyada hai — max stock barhayein ya sheet theek karein.";
                            continue;
                        }
                        // Fill in a barcode if this product was somehow created
                        // without one — never overwrites an existing barcode.
                        if (!$product->barcode) {
                            $attributes['barcode'] = $this->generateUniqueBarcode();
                        }
                        $oldStock = (float) $product->stock;
                        $product->update($attributes);
                        // H10 — stock as a logged adjustment delta, not
                        // an absolute overwrite.
                        $delta = $sheetStock - $oldStock;
                        if ($delta != 0.0) {
                            StockMovement::create([
                                'product_id' => $product->id,
                                'type' => 'adjustment',
                                'quantity' => $delta,
                                'reason' => "Excel import stock adjustment ({$oldStock} → {$sheetStock})",
                                'user_id' => auth()->id(),
                            ]);
                            $product->increment('stock', $delta);
                        }
                        $updated++;
                    } else {
                        // New product only: sale_price starts at the
                        // sheet's Rate (NOT reduced by the Purchase
                        // Discount % — that discount is what we get from
                        // the supplier, it has nothing to do with what we
                        // charge customers), and opening stock is the
                        // sheet value. M7 — opening price history row.
                        $newProduct = Product::create(array_merge($attributes, [
                            'name' => $name,
                            'sku' => $this->generateUniqueSku($attributes['category']),
                            'barcode' => $this->generateUniqueBarcode(),
                            'unit' => 'piece',
                            'is_active' => true,
                            'stock' => $sheetStock,
                            'sale_price' => $attributes['purchase_price'],
                        ]));
                        ProductPriceHistory::create([
                            'product_id' => $newProduct->id,
                            'old_price' => 0,
                            'new_price' => $newProduct->sale_price,
                            'changed_by' => auth()->id(),
                        ]);
                        $created++;
                    }
                }
            });
        } catch (\RuntimeException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        $msg = "Import done — {$updated} product(s) updated, {$created} new product(s) created. Matched products ki Sale Price change nahi hui; stock sheet value ke mutabiq logged adjustment se update hua.";
        if ($skipped) {
            $msg .= " {$skipped} row(s) skipped — no product name.";
        }
        if ($blocked) {
            $shown = array_slice($blocked, 0, 20);
            $msg .= ' Blocked rows: ' . implode(' | ', $shown);
            if (count($blocked) > 20) {
                $msg .= ' (aur ' . (count($blocked) - 20) . ' aur).';
            }
        }

        return back()->with('status', $msg);
    }

    /** A readable SKU instead of a mangled full-name concatenation — a
     *  3-letter prefix from the category (or "GEN" if there isn't one)
     *  plus a zero-padded running number, e.g. "FOO-0001", "TAB-0012".
     *  Numbering is per-prefix, so different categories don't fight over
     *  the same counter. */
    private function generateUniqueSku(?string $category): string
    {
        $letters = preg_replace('/[^A-Za-z]/', '', (string) $category);
        $prefix = strtoupper(substr($letters, 0, 3)) ?: 'GEN';

        $n = Product::where('sku', 'like', "{$prefix}-%")->count() + 1;
        do {
            $sku = $prefix . '-' . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $n++;
        } while (Product::where('sku', $sku)->exists());

        return $sku;
    }
}
