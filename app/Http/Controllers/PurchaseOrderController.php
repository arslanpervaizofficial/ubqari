<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\ProductPriceHistory;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderDraft;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurchaseOrderController extends Controller
{
    /** Lists all POs. M6 — this is a GET and stays read-only: it used to
     *  call recalculateTotals() (a write) on every received PO on every
     *  load, churning updated_at and risking a lost-update race with a
     *  concurrent edit. Totals are now recomputed only on write paths
     *  (receive, updateItem, updateDiscount, destroyItem, supplier
     *  returns, restore), so what this page shows is the stored total. */
    public function index()
    {
        $purchaseOrders = PurchaseOrder::with('supplier', 'items')->latest()->paginate(20);
        return view('purchase_orders.index', compact('purchaseOrders'));
    }

    /** Moves a purchase order to Trash. If it had been received, the stock
     *  it added is reversed first (and put back if it's ever Restored from
     *  Trash) — same principle as deleting a Sales Order reversing the sale.
     *  A pending (not yet received) PO never touched stock, so there is
     *  nothing to reverse for those. */
    public function destroy(PurchaseOrder $purchaseOrder)
    {
        try {
            DB::transaction(function () use ($purchaseOrder) {
                $purchaseOrder->reverseReceivedEffects();
                $purchaseOrder->delete();
                // v2.2 supplier ledger hook, v3.1.0 (M10): supplier
                // sync INSIDE the transaction (was after it). A
                // trashed PO no longer counts in received totals
                // (purchaseOrders() excludes soft-deleted rows), so
                // what we owe this supplier has changed — and the
                // delete is visible to the sync inside the same
                // transaction.
                if ($purchaseOrder->supplier_id) {
                    Supplier::lockForUpdate()->find($purchaseOrder->supplier_id)?->syncBalance();
                }
            });
        } catch (\RuntimeException $e) {
            return back()->withErrors(['purchase_order' => $e->getMessage()]);
        }

        return back()->with('status', "PO #{$purchaseOrder->id} moved to Trash — stock adjusted.");
    }

    /** Bulk version of destroy() for the checked rows on the PO list. Each
     *  PO is reversed+deleted in its own transaction so one that can't be
     *  safely reversed (see PurchaseOrder::reverseReceivedEffects) is
     *  skipped without blocking the rest of the batch. */
    public function destroySelected(Request $request)
    {
        $ids = (array) $request->input('ids', []);
        $purchaseOrders = PurchaseOrder::whereIn('id', $ids)->get();

        [$deleted, $skipped] = $this->deleteEach($purchaseOrders);

        return back()->with('status', $this->deleteSummary($deleted, $skipped));
    }

    /** Bulk version of destroy() for every purchase order currently listed
     *  (this list has no search/filter yet, so "all" really means all). */
    public function destroyAll()
    {
        $purchaseOrders = PurchaseOrder::all();

        [$deleted, $skipped] = $this->deleteEach($purchaseOrders);

        return back()->with('status', $this->deleteSummary($deleted, $skipped));
    }

    /** Shared loop for the two bulk-delete actions above. */
    private function deleteEach($purchaseOrders): array
    {
        $deleted = 0;
        $skipped = [];

        foreach ($purchaseOrders as $purchaseOrder) {
            try {
                DB::transaction(function () use ($purchaseOrder) {
                    $purchaseOrder->reverseReceivedEffects();
                    $purchaseOrder->delete();
                    // v2.2 supplier ledger hook, v3.1.0 (M10):
                    // supplier sync INSIDE the transaction (same
                    // reason as destroy()).
                    if ($purchaseOrder->supplier_id) {
                        Supplier::lockForUpdate()->find($purchaseOrder->supplier_id)?->syncBalance();
                    }
                });
                $deleted++;
            } catch (\RuntimeException $e) {
                $skipped[] = "PO #{$purchaseOrder->id}";
            }
        }

        return [$deleted, $skipped];
    }

    private function deleteSummary(int $deleted, array $skipped): string
    {
        $status = "{$deleted} purchase order(s) moved to Trash — stock adjusted.";
        if ($skipped) {
            $status .= ' Skipped (stock from these already sold, can\'t reverse safely): ' . implode(', ', $skipped) . '.';
        }
        return $status;
    }

    public function create(Request $request)
    {
        $suppliers = Supplier::orderBy('name')->get();
        $products = Product::active()->orderBy('name')->get();

        // Built here as a plain array (not inside the Blade file) so there is
        // zero ambiguity for Blade/PHP to parse — the view just echoes this
        // single variable, with no inline closures or embedded commas.
        $productsForJs = $products->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'sku' => $p->sku,
            'cost' => $p->purchase_price,
            'discount' => $p->purchase_discount_percent,
            'max_discount' => $p->max_discount_percent,
            'stock' => $p->stock,
            'unit' => $p->unit,
        ])->values();

        $preselectedLines = [];
        $bulkIds = $request->input('product_id');
        $bulkQtys = $request->input('qty');

        if (is_array($bulkIds)) {
            foreach ($bulkIds as $i => $pid) {
                if (!$pid) continue;
                $preselectedLines[] = ['product_id' => (int) $pid, 'qty' => (float) ($bulkQtys[$i] ?? 1)];
            }
        } elseif ($request->filled('product_id')) {
            $preselectedLines[] = [
                'product_id' => (int) $request->input('product_id'),
                'qty' => (float) ($request->input('suggested_qty') ?: 1),
            ];
        }

        // FIX 1 — Draft: the user may have a temporary (not final) save.
        // A draft is NOT a purchase order row: it lives in its own table,
        // never appears in the PO list / Purchase Report, and can never be
        // "Mark Received". Only "Create Purchase Order" creates a real PO.
        // v1.1 HARDENING — if the migration has not been run yet, the
        // draft table does not exist. That must NEVER crash this page:
        // the form still opens (browser draft copy still works), and the
        // user just gets no server draft until `php artisan migrate`.
        $draft = null;
        $draftLines = [];
        if (Schema::hasTable('purchase_order_drafts')) {
            if ($request->filled('draft_id')) {
                $draft = PurchaseOrderDraft::where('user_id', auth()->id())->find($request->input('draft_id'));
            }
            if (!$draft) {
                $draft = PurchaseOrderDraft::where('user_id', auth()->id())->latest()->first();
            }
            $draftLines = $draft ? ($draft->items ?? []) : [];
        }

        // Rows coming back after a validation error (old input) or from a
        // draft may name a product that is no longer "active" — it would
        // otherwise not be in PRODUCTS, so the row would render blank.
        // Merge those products into the JS list so every restored row
        // shows its real product name.
        $restoredIds = collect()
            ->merge(collect(old('items', []))->pluck('product_id'))
            ->merge(collect($draftLines)->pluck('product_id'))
            ->merge(collect($preselectedLines)->pluck('product_id'))
            ->filter()->unique()->values();
        if ($restoredIds->isNotEmpty()) {
            $extra = Product::whereIn('id', $restoredIds)->get()->map(fn ($p) => [
                'id' => $p->id, 'name' => $p->name, 'sku' => $p->sku,
                'cost' => $p->purchase_price, 'discount' => $p->purchase_discount_percent,
                'max_discount' => $p->max_discount_percent, 'stock' => $p->stock, 'unit' => $p->unit,
            ]);
            $productsForJs = $productsForJs->merge($extra)->unique('id')->values();
        }

        return view('purchase_orders.create', compact('suppliers', 'products', 'productsForJs', 'preselectedLines', 'draft', 'draftLines'));
    }

    /** FIX 1 — Save / autosave as a TEMPORARY draft. This creates NO
     *  purchase order, touches NO stock, and appears in NO report.
     *  Validation is deliberately loose here: a half-filled row is
     *  allowed in a draft (the user is mid-typing), it is only the
     *  FINAL create that demands complete rows. One draft per user:
     *  a later save updates the same draft instead of piling up rows. */
    public function storeDraft(Request $request)
    {
        // v1.1 HARDENING — same as create(): a missing draft table must
        // never crash an autosave. The browser copy (localStorage) keeps
        // working, and the user is told plainly to run the migration.
        // This check MUST come before validate(), because the
        // 'exists:purchase_order_drafts,id' rule itself queries the table.
        if (!Schema::hasTable('purchase_order_drafts')) {
            $msg = 'Server draft save nahi hua: purchase_order_drafts table nahi mili. php artisan migrate chalayein. (Browser draft abhi bhi save hai.)';
            if ($request->expectsJson() || $request->ajax()) {
                return response()->json(['draft_id' => null, 'saved_at' => null, 'message' => $msg, 'migration_pending' => true]);
            }
            return back()->withErrors(['draft' => $msg]);
        }

        $data = $request->validate([
            'draft_id' => ['nullable', 'exists:purchase_order_drafts,id'],
            'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items' => ['nullable', 'array'],
        ]);

        // Keep rows that have anything typed in them (even a partial
        // row — the user may finish it after logging back in), drop
        // rows that are completely blank.
        $items = collect($data['items'] ?? [])
            ->filter(fn ($line) => is_array($line) && (
                !empty($line['product_id'])
                || ($line['quantity'] ?? '') !== ''
                || ($line['cost_price'] ?? '') !== ''
            ))
            ->map(fn ($line) => [
                'product_id' => $line['product_id'] ?? null,
                'quantity' => $line['quantity'] ?? null,
                'cost_price' => $line['cost_price'] ?? null,
                'discount_percent' => $line['discount_percent'] ?? 0,
                'max_discount_percent' => $line['max_discount_percent'] ?? 0,
            ])
            ->values()->all();

        $draft = null;
        if (!empty($data['draft_id'])) {
            $draft = PurchaseOrderDraft::where('user_id', auth()->id())->find($data['draft_id']);
        }
        if (!$draft) {
            $draft = PurchaseOrderDraft::where('user_id', auth()->id())->latest()->first();
        }
        if (!$draft) {
            $draft = new PurchaseOrderDraft(['user_id' => auth()->id()]);
        }
        $draft->fill([
            'supplier_id' => $data['supplier_id'] ?? $draft->supplier_id,
            'discount_percent' => $data['discount_percent'] ?? $draft->discount_percent ?? 0,
            'items' => $items,
        ]);
        $draft->save();

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'draft_id' => $draft->id,
                'saved_at' => $draft->updated_at->format('H:i:s'),
                'message' => 'Draft save ho gaya hai. Ye final order nahi hai, stock update nahi hua.',
            ]);
        }
        return back()->with('status', 'Draft save ho gaya hai. Ye final order nahi hai, stock update nahi hua.');
    }

    public function store(Request $request)
    {
        // FIX 2 (server half) — an untouched blank "new product" row used
        // to fail validation (blank product/quantity/cost are all
        // "required"), and the re-rendered form ignored old() input, so
        // the user lost everything AND the row's remove button. Now:
        // fully-blank rows are dropped BEFORE validation (they are not
        // an error), and a partially-filled row fails with its ROW NUMBER
        // so the user knows exactly which row to fix or delete.
        $filteredItems = collect($request->input('items', []))
            ->filter(fn ($line) => is_array($line) && (
                !empty($line['product_id'])
                || ($line['quantity'] ?? '') !== ''
                || ($line['cost_price'] ?? '') !== ''
            ))
            ->values()->all();
        foreach ($filteredItems as $i => $line) {
            if (empty($line['product_id']) || ($line['quantity'] ?? '') === '' || ($line['cost_price'] ?? '') === '') {
                return back()->withInput()->withErrors([
                    'items' => 'Row ' . ($i + 1) . ': product, quantity aur cost teeno lazmi hain. '
                        . 'Ya to row poori bharein, ya usay cross (×) se delete kar dein.',
                ]);
            }
        }
        if (empty($filteredItems)) {
            return back()->withInput()->withErrors(['items' => 'Kam az kam aik product lazmi hai.']);
        }
        // H12d — the same product twice on one PO (at possibly different
        // costs) used to be accepted silently, and receive() then
        // overwrote the product's cost reference per line, last line
        // winning. Reject it with the product's name instead of merging
        // silently, so the user picks one line per product.
        $seenProductIds = [];
        foreach ($filteredItems as $line) {
            if (!empty($line['product_id'])) {
                if (in_array($line['product_id'], $seenProductIds, false)) {
                    $dupName = Product::find($line['product_id'])?->name ?? ('product #' . $line['product_id']);
                    return back()->withInput()->withErrors([
                        'items' => "\"{$dupName}\" is PO me do dafa hai. Aik product ki aik hi line rakhein — quantity ya cost aik line me jama kar dein.",
                    ]);
                }
                $seenProductIds[] = $line['product_id'];
            }
        }
        $request->merge(['items' => $filteredItems]);

        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'items.*.cost_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.max_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        if (collect($data['items'])->every(fn ($line) => (float) $line['quantity'] === 0.0)) {
            return back()->withErrors(['items' => 'At least one product needs a quantity greater than 0.'])->withInput();
        }

        $po = DB::transaction(function () use ($data) {
            $po = PurchaseOrder::create([
                'supplier_id' => $data['supplier_id'],
                'status' => 'pending',
                'subtotal' => 0,
                'discount_percent' => $data['discount_percent'] ?? 0,
                'discount_amount' => 0,
                'total' => 0,
            ]);

            $subtotal = 0;
            foreach ($data['items'] as $line) {
                // A line left at 0 quantity means "picked this product but
                // not actually ordering it this time" (e.g. added it via
                // the product picker, then changed their mind) — skip it
                // entirely rather than forcing it in as a real line. It used
                // to be REQUIRED to be at least 0.01 to pass validation,
                // which is exactly why 0.01-quantity phantom lines (like the
                // "Ubqari Sabaya Perfume" ones at Rs 20 each) ended up in
                // real purchase orders and quietly inflated every total
                // downstream — the Purchase/Capital reports, this PO's own
                // stored total, all of it.
                if ((float) $line['quantity'] === 0.0) {
                    continue;
                }

                $line['discount_percent'] = $line['discount_percent'] ?? 0;

                // H12e — Max Discount % isn't a Purchase Order attribute
                // at all, it's the product's own billing-discount ceiling.
                // It used to be pushed onto the product here, so a
                // purchasing action silently changed a billing rule (the
                // discount ceiling cashiers face) with no history or
                // confirmation. That side-effect is removed: the value is
                // simply dropped, and the ceiling is only ever changed on
                // the product form itself.
                unset($line['max_discount_percent']);

                $po->items()->create($line);

                // v3.1.0 (M13): use the SAME rounding as
                // recalculateTotals() — the per-unit net cost ROUNDED
                // to 2dp via the PurchaseOrderItem accessor — so the
                // store-time total cannot shift by cents at receive
                // time, when totals are recomputed from received
                // quantities × rounded net cost.
                $netCost = round($line['cost_price'] * (1 - $line['discount_percent'] / 100), 2);
                $subtotal += $line['quantity'] * $netCost;
            }

            // Overall order-level discount — applied on top of each line's own
            // discount, for a special/blanket discount the supplier gives on
            // the whole order rather than per product.
            $overallDiscountPercent = $data['discount_percent'] ?? 0;
            $discountAmount = round($subtotal * ($overallDiscountPercent / 100), 2);
            $total = $subtotal - $discountAmount;

            $po->update([
                'subtotal' => round($subtotal, 2),
                'discount_amount' => $discountAmount,
                'total' => round($total, 2),
            ]);

            return $po;
        });

        // FIX 1 — the final order now exists, so the temporary draft
        // (server copy) is done with. The browser copy is cleared by the
        // PO list page when it sees this "created." status (see the
        // snippet in routes-and-index-patch.txt).
        if ($request->filled('draft_id') && Schema::hasTable('purchase_order_drafts')) {
            PurchaseOrderDraft::where('user_id', auth()->id())
                ->where('id', $request->input('draft_id'))->delete();
        }

        return redirect()->route('purchase-orders.index')->with('status', "PO #{$po->id} created.");
    }

    /** Shows the receiving form — actual received quantity per line is editable
     *  here, since suppliers often short-ship (or occasionally over-ship)
     *  against what was originally ordered. Pre-fills with the ordered qty. */
    public function receiveForm(PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status === 'received') {
            return redirect()->route('purchase-orders.index')->withErrors(['error' => 'This PO was already received.']);
        }
        $purchaseOrder->load('items.product', 'supplier');
        return view('purchase_orders.receive', compact('purchaseOrder'));
    }

    /** Marking a PO received auto-increases stock by the ACTUAL received quantity
     *  (not necessarily what was ordered) and logs a purchase movement per line.
     *
     *  H12 — (a) every PO item key must be present in the payload: a line
     *  omitted from the POST used to be silently received at its full
     *  ordered quantity via the ?? fallback. (b) Receiving more than
     *  ordered is rejected unless the explicit allow_over_receive flag
     *  is set (the receive form has a checkbox for it), so a typo like
     *  1000 instead of 100 cannot inflate stock 10x unnoticed.
     *  (c) The PO row is locked inside the transaction BEFORE the status
     *  check, so two concurrent submissions cannot both pass the
     *  "not already received" check and both add stock.
     *  M7 — a change to the product's purchase_price /
     *  purchase_discount_percent here is recorded in
     *  ProductPriceHistory. M8 — receiving past a product's max_stock
     *  (when set) is rejected.
     *  v3.0 FEATURE 1 — per-line OPTIONAL batch_no + expiry_date inputs
     *  (see the receive form): each received line also creates one
     *  ProductBatch. A blank batch_no auto-generates
     *  "PO-{po_id}-L{line}" (1-based line position); a line posted
     *  without any batch inputs at all (old form posts) gets an auto
     *  batch_no and null expiry. Batch creation is guarded so it can
     *  NEVER fail a receive (missing table, bad input) — product.stock
     *  stays authoritative. Lines received at 0 create no batch: there
     *  is nothing to track. */
    public function receive(Request $request, PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status === 'received') {
            return back()->withErrors(['error' => 'This PO was already received.']);
        }

        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*.received_quantity' => ['required', 'numeric', 'min:0'],
            // v3.0 FEATURE 1 — both optional: old form posts carry
            // neither key, and validation must keep passing for them.
            'items.*.batch_no' => ['nullable', 'string', 'max:255'],
            'items.*.expiry_date' => ['nullable', 'date'],
            'allow_over_receive' => ['nullable', 'boolean'],
        ]);

        $poItems = $purchaseOrder->items()->with('product')->get();

        // H12a — every line must be accounted for in the payload.
        $missing = $poItems->filter(fn ($item) => !isset($data['items'][$item->id]['received_quantity']))
            ->map(fn ($item) => $item->product?->name ?? ('item #' . $item->id))->values();
        if ($missing->isNotEmpty()) {
            return back()->withInput()->withErrors([
                'items' => 'Received quantity missing for: ' . $missing->implode(', ') . '. Har line ki received quantity lazmi hai — koi line chhupi hui submit nahi ho sakti.',
            ]);
        }

        // H12b — over-receipt needs the explicit override flag.
        if (!$request->boolean('allow_over_receive')) {
            foreach ($poItems as $item) {
                $receivedQty = (float) $data['items'][$item->id]['received_quantity'];
                if ($receivedQty > (float) $item->quantity) {
                    return back()->withInput()->withErrors([
                        'items' => "\"{$item->product->name}\" ki received quantity ({$receivedQty}) ordered quantity ({$item->quantity}) se zyada hai. "
                            . 'Agar ye waqai over-shipment hai to "Over-shipment allow karein" tick karke dobara submit karein.',
                    ]);
                }
            }
        }

        // v3.1.0 (M17) — WARN, do not block: collect lines whose submitted
        // expiry date is already in the past. The receive still goes through;
        // the warning is appended to the redirect status below.
        $pastExpiryWarnings = [];
        foreach ($poItems as $item) {
            $expiry = $data['items'][$item->id]['expiry_date'] ?? null;
            if ($expiry !== null && $expiry !== '' && $expiry < now()->toDateString()) {
                $pastExpiryWarnings[] = $item->product?->name ?? ('item #' . $item->id);
            }
        }

        // v3.1.0 (L6) — WARN, do not block: flag user-entered batch numbers
        // that already exist for the same product. The receive still goes
        // through; duplicates are a bookkeeping confusion risk, not a
        // stock-corruption risk, so this is a warning, not an error.
        $duplicateBatchWarnings = [];
        if (Schema::hasTable('product_batches')) {
            foreach ($poItems as $item) {
                $submittedBatchNo = trim((string) ($data['items'][$item->id]['batch_no'] ?? ''));
                if ($submittedBatchNo === '') {
                    continue; // auto-generated PO-{id}-L{n} below; not user input
                }
                $exists = \App\Models\ProductBatch::where('product_id', $item->product_id)
                    ->where('batch_no', $submittedBatchNo)
                    ->exists();
                if ($exists) {
                    $duplicateBatchWarnings[] = ($item->product?->name ?? ('item #' . $item->id)) . ' (' . $submittedBatchNo . ')';
                }
            }
        }

        try {
            DB::transaction(function () use ($purchaseOrder, $data) {
                // H12c — lock first, then check status inside the lock.
                $locked = PurchaseOrder::lockForUpdate()->findOrFail($purchaseOrder->id);
                if ($locked->status === 'received') {
                    throw new \RuntimeException('This PO was already received.');
                }

                // Projected stock per product, so the max_stock check
                // stays correct even if one product appears on two lines.
                $projectedStock = [];

                foreach ($locked->items()->with('product')->get() as $lineIndex => $item) {
                    $receivedQty = (float) $data['items'][$item->id]['received_quantity'];

                    // M8 — refuse receiving past max_stock when it is set.
                    $projectedStock[$item->product_id] = ($projectedStock[$item->product_id] ?? (float) $item->product->stock) + $receivedQty;
                    if ($item->product->max_stock !== null && $projectedStock[$item->product_id] > (float) $item->product->max_stock) {
                        throw new \RuntimeException(
                            "\"{$item->product->name}\" ki stock {$projectedStock[$item->product_id]} ho jaye gi, jo max stock ({$item->product->max_stock}) se zyada hai. "
                            . 'Max stock barhayein ya received quantity kam karein.'
                        );
                    }

                    $item->update(['received_quantity' => $receivedQty]);

                    if ($receivedQty > 0) {
                        $item->product->increment('stock', $receivedQty);
                        // Keep the product's Purchase Price / Purchase Discount %
                        // in sync with this PO line's own GROSS cost and discount
                        // — not the net figure. purchase_price must stay the raw
                        // supplier rate everywhere (Products form, Excel import,
                        // and here) so there's one consistent meaning for it;
                        // anything that needs the actual cost after discount
                        // should read Product::net_purchase_price instead.
                        // M7 — record the cost change in price history when
                        // either cost field actually changes. The history
                        // table has only old_price/new_price columns, so a
                        // cost change is stored there as the purchase-price
                        // movement (not a sale-price change).
                        $oldCost = $item->product->purchase_price;
                        $oldDiscount = $item->product->purchase_discount_percent;
                        $item->product->update([
                            'purchase_price' => $item->cost_price,
                            'purchase_discount_percent' => $item->discount_percent,
                        ]);
                        if ((float) $oldCost !== (float) $item->cost_price || (float) $oldDiscount !== (float) $item->discount_percent) {
                            ProductPriceHistory::create([
                                'product_id' => $item->product_id,
                                'old_price' => $oldCost,
                                'new_price' => $item->cost_price,
                                'changed_by' => auth()->id(),
                            ]);
                        }

                        StockMovement::create([
                            'product_id' => $item->product_id,
                            'type' => 'purchase',
                            'quantity' => $receivedQty,
                            'reason' => $receivedQty != $item->quantity
                                ? "PO #{$purchaseOrder->id} received (ordered {$item->quantity}, received {$receivedQty})"
                                : "PO #{$purchaseOrder->id} received",
                            'user_id' => auth()->id(),
                            'purchase_order_id' => $purchaseOrder->id,
                        ]);

                        // v3.0 FEATURE 1 — one batch per received line.
                        // Guarded + caught: a batch problem must never
                        // fail the receive itself.
                        try {
                            if (Schema::hasTable('product_batches')) {
                                $batchInput = $data['items'][$item->id] ?? [];
                                $batchNo = trim((string) ($batchInput['batch_no'] ?? ''));
                                if ($batchNo === '') {
                                    $batchNo = "PO-{$locked->id}-L" . ($lineIndex + 1);
                                }
                                $expiryDate = $batchInput['expiry_date'] ?? null;
                                if ($expiryDate === '') {
                                    $expiryDate = null;
                                }
                                ProductBatch::create([
                                    'product_id' => $item->product_id,
                                    'batch_no' => $batchNo,
                                    'expiry_date' => $expiryDate,
                                    'quantity' => $receivedQty,
                                    'remaining_quantity' => $receivedQty,
                                    'purchase_order_id' => $locked->id,
                                ]);
                            }
                        } catch (\Throwable $e) {
                            report($e);
                        }
                    }
                }
                // v3.1.0 (H13): record WHEN the PO was received
                // alongside the status. (The backfill of already-
                // received POs is a separate migration; this only
                // stamps new receives. received_at is in
                // PurchaseOrder::$fillable so this is not silently
                // dropped by mass-assignment guarding.)
                $locked->update(['status' => 'received', 'received_at' => now()]);

                // Realign subtotal/total with what was ACTUALLY received
                // right away, rather than leaving the ordered-quantity
                // figures stored at store() time sitting there — the
                // Purchase Report reads total straight off the row.
                $this->recalculateTotals($locked->fresh());

                // v2.2 supplier ledger hook: this PO is now received, so
                // what we owe its supplier has changed — re-sync it
                // inside the same transaction.
                if ($locked->supplier_id) {
                    Supplier::find($locked->supplier_id)?->syncBalance();
                }
            });
        } catch (\RuntimeException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        $status = 'PO marked received — stock updated with actual received quantities.';
        if ($pastExpiryWarnings !== []) {
            $status .= ' Warning: expiry date already passed for: ' . implode(', ', $pastExpiryWarnings) . '. This stock is expired on arrival — check the Batches / Expiry page before selling it.';
        }
        if ($duplicateBatchWarnings !== []) {
            $status .= ' Warning: batch number already exists for this product: ' . implode(', ', $duplicateBatchWarnings) . '. The new batch was still created — check the Batches / Expiry page if the numbers should differ.';
        }

        return redirect()->route('purchase-orders.index')->with('status', $status);
    }

    /** Read-only-looking but click-to-edit view of a received PO. Only
     *  three things are ever editable here — Still held (net of
     *  returns: the quantity from this line still in stock after any
     *  supplier returns, NOT the gross quantity that arrived), each
     *  line's own Discount % ("supplier discount"), and the PO's
     *  overall Discount % (the blanket discount, separate from any one
     *  line's own) — never cost_price or which products are on the order,
     *  since those aren't corrections so much as a different purchase
     *  entirely. Pending (not yet received) POs use the existing
     *  create/receive flow instead; this is purely for fixing a mistake
     *  noticed after the fact on one already marked received. */
    public function show(PurchaseOrder $purchaseOrder)
    {
        abort_unless($purchaseOrder->status === 'received', 404);
        $purchaseOrder->load('items.product', 'supplier');
        // M6 — no recalculateTotals() here any more: this is a GET and
        // must not write. Totals are kept correct by the write paths
        // (receive/updateItem/updateDiscount/destroyItem/supplier
        // returns), so this page simply shows the stored figures.
        // FIX 4 — per-line delete permission for the show view. The
        // button is only enabled when this exact line may be deleted;
        // otherwise the reason is shown next to the disabled button.
        foreach ($purchaseOrder->items as $item) {
            $item->delete_block_reason = $this->itemDeleteBlockReason($purchaseOrder, $item);
            $item->can_delete = $item->delete_block_reason === null;
        }
        return view('purchase_orders.show', compact('purchaseOrder'));
    }

    /** Corrects one line item after the PO was already received — either
     *  the actual received quantity or that line's own discount. Whichever
     *  one changes, stock and the PO's totals are kept in sync: a changed
     *  quantity adjusts the product's stock by the DELTA (not a blind
     *  re-set, so it composes correctly with anything else that's touched
     *  stock since), and either change recomputes the PO's subtotal/total
     *  from scratch off every line's current numbers. */
    public function updateItem(Request $request, PurchaseOrder $purchaseOrder, PurchaseOrderItem $item)
    {
        abort_unless($purchaseOrder->status === 'received', 404);
        abort_unless($item->purchase_order_id === $purchaseOrder->id, 404);

        $data = $request->validate([
            'received_quantity' => ['nullable', 'numeric', 'min:0'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        // H7 — guard on a received-quantity correction, returned as
        // JSON 422 so the show page's patch() surfaces the message
        // instead of silently applying a bad correction: a reduction
        // may never take product stock below 0.
        // v2.2 (R4): the old second guard — "never below the quantity
        // already returned" — is REMOVED. It assumed received_quantity
        // was the gross figure, but under the H8 invariant the field
        // holds the NET quantity still held (returns already subtracted
        // at return time), so the old guard both refused legitimate net
        // corrections and, worse, let a gross-minded correction (e.g.
        // "actually only 9 arrived" on a line showing 6 after 4 were
        // returned) silently overstate stock by the returned amount.
        // The field is now labelled "Still held (net of returns)" on
        // the show page so the meaning is unambiguous, and the stock
        // guard below is the only floor.
        if (array_key_exists('received_quantity', $data) && $data['received_quantity'] !== null) {
            $delta = (float) $data['received_quantity'] - (float) $item->received_quantity;
            if ($delta < 0 && (float) $item->product->stock + $delta < 0) {
                return response()->json([
                    'message' => "Received quantity kam karne se \"{$item->product->name}\" ki stock negative ho jaye gi (maujud stock {$item->product->stock}, kami {$delta}).",
                ], 422);
            }
        }

        // v3.1.0 (L11f/M10/H7): the pre-check above reads stale
        // values, so the guard, the stock move, the totals recompute
        // AND the supplier sync all run again inside ONE transaction
        // on locked rows (PO, then item, then product — same order
        // every time). The in-transaction guard re-reads the locked
        // product's stock and the locked item's quantity, so a
        // concurrent sale between the pre-check and this transaction
        // cannot slip a negative stock through. A guard failure inside
        // is returned as JSON 422, same as the pre-check.
        try {
            DB::transaction(function () use ($purchaseOrder, $item, $data) {
                $lockedPo = PurchaseOrder::lockForUpdate()->findOrFail($purchaseOrder->id);
                if ($lockedPo->status !== 'received') {
                    throw new \RuntimeException('This PO is no longer received, so its lines cannot be corrected.');
                }
                $lockedItem = PurchaseOrderItem::lockForUpdate()->findOrFail($item->id);
                $lockedProduct = Product::lockForUpdate()->findOrFail($lockedItem->product_id);

                if (array_key_exists('received_quantity', $data) && $data['received_quantity'] !== null) {
                    $oldQty = $lockedItem->received_quantity;
                    $newQty = $data['received_quantity'];
                    $delta = $newQty - $oldQty;

                    if ($delta < 0 && (float) $lockedProduct->stock + $delta < 0) {
                        throw new \RuntimeException(
                            "Received quantity kam karne se \"{$lockedProduct->name}\" ki stock negative ho jaye gi (maujud stock {$lockedProduct->stock}, kami {$delta})."
                        );
                    }

                    if ($delta !== 0.0) {
                        $lockedProduct->increment('stock', $delta);
                        ProductBatch::mirrorStockDelta((int) $lockedItem->product_id, (float) $delta, ['record' => "PO #{$lockedPo->id}"]);
                        StockMovement::create([
                            'product_id' => $lockedItem->product_id,
                            'type' => 'purchase',
                            'quantity' => $delta,
                            'reason' => "PO #{$lockedItem->purchase_order_id} received quantity corrected ({$oldQty} → {$newQty})",
                            'user_id' => auth()->id(),
                            'purchase_order_id' => $lockedItem->purchase_order_id,
                        ]);
                    }

                    $lockedItem->update(['received_quantity' => $newQty]);
                }

                if (array_key_exists('discount_percent', $data) && $data['discount_percent'] !== null) {
                    $lockedItem->update(['discount_percent' => $data['discount_percent']]);
                }

                // Keep the product's cost reference (gross Purchase Price +
                // Purchase Discount %) in sync with this line's current
                // numbers, same as receive() does originally.
                $lockedProduct->update([
                    'purchase_price' => $lockedItem->cost_price,
                    'purchase_discount_percent' => $lockedItem->discount_percent,
                ]);

                // Totals + v2.2 supplier ledger hook, both INSIDE
                // the transaction (M10), on the locked PO / locked
                // supplier row — the old code did both after the
                // transaction, where a concurrent event could
                // interleave and leave a stale total or balance behind.
                $this->recalculateTotals($lockedPo);
                if ($lockedPo->supplier_id) {
                    Supplier::lockForUpdate()->find($lockedPo->supplier_id)?->syncBalance();
                }
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $item->refresh();
        $purchaseOrder->refresh();
        $batchWarning = ProductBatch::takeLastBatchError();

        return response()->json([
            'received_quantity' => $item->received_quantity,
            'discount_percent' => $item->discount_percent,
            // Deliberately received_quantity × net cost, not the
            // quantity-ordered-based line_total accessor (that one's used
            // elsewhere — e.g. the supplier-return discount lookup — where
            // a per-unit ratio independent of how much actually arrived is
            // what's wanted). This correction screen exists specifically
            // to reconcile the total with what was ACTUALLY received.
            'line_total' => round($item->received_quantity * $item->net_cost_price, 2),
            'product_stock' => $item->product->fresh()->stock,
            'po' => [
                'subtotal' => $purchaseOrder->subtotal,
                'discount_amount' => $purchaseOrder->discount_amount,
                'total' => $purchaseOrder->total,
            ],
            // v3.1.0 (H8): null when batches mirrored cleanly.
            'batch_warning' => $batchWarning,
        ]);
    }

    /** The PO's own overall Discount % — a blanket discount applied once
     *  across every line's already-net subtotal, separate from and on top
     *  of each line's individual discount. */
    public function updateDiscount(Request $request, PurchaseOrder $purchaseOrder)
    {
        abort_unless($purchaseOrder->status === 'received', 404);

        $data = $request->validate([
            'discount_percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        // v3.1.0 (M10): this method previously had NO transaction at
        // all — the discount update, the totals recompute and the
        // supplier sync could interleave with concurrent events. Now
        // one transaction covers all three, on the locked PO row
        // (status re-checked inside) and the locked supplier row.
        try {
            DB::transaction(function () use ($purchaseOrder, $data) {
                $locked = PurchaseOrder::lockForUpdate()->findOrFail($purchaseOrder->id);
                if ($locked->status !== 'received') {
                    throw new \RuntimeException('This PO is no longer received, so its discount cannot be changed.');
                }
                $locked->update(['discount_percent' => $data['discount_percent']]);
                $this->recalculateTotals($locked);

                // v2.2 supplier ledger hook (extra, same pattern as updateItem):
                // the overall discount changes the PO total, so the supplier
                // balance moves with it.
                if ($locked->supplier_id) {
                    Supplier::lockForUpdate()->find($locked->supplier_id)?->syncBalance();
                }
            });
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $purchaseOrder->refresh();

        return response()->json([
            'discount_percent' => $purchaseOrder->discount_percent,
            'subtotal' => $purchaseOrder->subtotal,
            'discount_amount' => $purchaseOrder->discount_amount,
            'total' => $purchaseOrder->total,
        ]);
    }

    /** FIX 4 — Delete ONE line of a purchase order.
     *
     *  Pending PO: stock was never touched, so the line is simply
     *  removed and the PO totals are recomputed from the ordered
     *  quantities that remain.
     *
     *  Received ("final") PO: the line may be deleted ONLY if nothing
     *  was sold from the stock this line brought in. The check is in
     *  itemDeleteBlockReason() and mirrors the guard the codebase
     *  already uses for deleting a whole PO (PurchaseOrder::
     *  reverseReceivedEffects): a sale after this PO was received, a
     *  return already made against the line, or stock lower than what
     *  this line received all block the delete with a plain reason.
     *  When allowed, stock is reduced by exactly this line's RECEIVED
     *  quantity in the same transaction, and an "adjustment" stock
     *  movement is logged — the original purchase movement is kept
     *  (it is shared by purchase_order_id across all lines, so deleting
     *  it would corrupt the other lines' history; the adjustment nets
     *  this line out instead). Stock can never go negative this way. */
    public function destroyItem(PurchaseOrder $purchaseOrder, PurchaseOrderItem $item)
    {
        abort_unless($item->purchase_order_id === $purchaseOrder->id, 404);
        abort_unless(in_array($purchaseOrder->status, ['pending', 'received']), 404);

        if ($reason = $this->itemDeleteBlockReason($purchaseOrder, $item)) {
            return back()->withErrors(['purchase_order_item' => $reason]);
        }

        try {
            DB::transaction(function () use ($purchaseOrder, $item) {
                if ($purchaseOrder->status === 'pending') {
                    $item->delete();
                    $remaining = $purchaseOrder->items()->get();
                    $subtotal = $remaining->sum(fn ($line) => $line->quantity * $line->net_cost_price);
                    $discountAmount = round($subtotal * ($purchaseOrder->discount_percent / 100), 2);
                    $purchaseOrder->update([
                        'subtotal' => round($subtotal, 2),
                        'discount_amount' => $discountAmount,
                        'total' => round($subtotal - $discountAmount, 2),
                    ]);
                    return;
                }

                // Received PO: reverse exactly this line's stock effect.
                // v3.1.0: the quantity is re-read from the LOCKED item
                // row, not the pre-transaction instance — a supplier
                // return landing between the block pre-check and this
                // transaction must not leave a stale (too large)
                // quantity being deducted.
                $lockedItem = PurchaseOrderItem::lockForUpdate()->findOrFail($item->id);
                $receivedQty = (float) $lockedItem->received_quantity;
                $product = Product::lockForUpdate()->findOrFail($item->product_id);
                if ($receivedQty > 0) {
                    if ($product->stock < $receivedQty) {
                        throw new \RuntimeException(
                            "Can't delete this line: \"{$product->name}\" only has {$product->stock} in stock, "
                            . "but this PO brought in {$receivedQty} — some of it has already been sold."
                        );
                    }
                    $product->decrement('stock', $receivedQty);
                    // v3.1.0 (H7): the line's stock decrease is
                    // mirrored in batches, in this same transaction.
                    ProductBatch::mirrorStockDelta((int) $item->product_id, -$receivedQty, ['record' => "PO #{$purchaseOrder->id}"]);
                    StockMovement::create([
                        'product_id' => $item->product_id,
                        'type' => 'adjustment',
                        'quantity' => -$receivedQty,
                        'reason' => "PO #{$purchaseOrder->id} line deleted: {$product->name} ({$receivedQty} removed from stock)",
                        'user_id' => auth()->id(),
                        'purchase_order_id' => $purchaseOrder->id,
                    ]);
                }
                $item->delete();
                $this->recalculateTotals($purchaseOrder->fresh());

                // v2.2 supplier ledger hook, v3.1.0 (M10): supplier
                // sync INSIDE the transaction (was after it). The
                // pending branch returns before this point, which is
                // fine — pending POs never counted as owed, so a sync
                // there would be a no-op anyway.
                if ($purchaseOrder->supplier_id) {
                    Supplier::lockForUpdate()->find($purchaseOrder->supplier_id)?->syncBalance();
                }
            });
        } catch (\RuntimeException $e) {
            return back()->withErrors(['purchase_order_item' => $e->getMessage()]);
        }

        $msg = $purchaseOrder->status === 'received'
            ? "Product line delete ho gai — stock {$item->received_quantity} se kam kar diya gaya."
            : 'Product line delete ho gai.';
        return back()->with('status', $msg);
    }

    /** Returns null when the line may be deleted, otherwise the plain
     *  reason that is shown to the user (and used to disable the button
     *  on the show page). Kept in one place so show() and destroyItem()
     *  can never disagree. */
    private function itemDeleteBlockReason(PurchaseOrder $purchaseOrder, PurchaseOrderItem $item): ?string
    {
        if ($purchaseOrder->status === 'pending') {
            return null; // pending never touched stock
        }
        if ($purchaseOrder->status !== 'received') {
            return 'Is PO ki line delete nahi ho sakti.';
        }

        $returnedQty = (float) $item->returns()->sum('quantity');
        if ($returnedQty > 0) {
            return "Ye line delete nahi ho sakti: is line ki {$returnedQty} unit return ho chuki hai.";
        }

        // Sale check (the user's rule, exactly): a "sale" stock movement
        // for this product AFTER this PO's own purchase movement means
        // stock from this PO has gone out in a sale. Movement IDs are
        // sequential, so id > purchase movement id is the reliable
        // "after" test even when two movements share a timestamp.
        $purchaseMovement = StockMovement::where('purchase_order_id', $purchaseOrder->id)
            ->where('product_id', $item->product_id)
            ->where('type', 'purchase')
            ->orderBy('id')->first();
        if ($purchaseMovement) {
            $soldAfter = StockMovement::where('product_id', $item->product_id)
                ->where('type', 'sale')
                ->where('id', '>', $purchaseMovement->id)
                ->exists();
            if ($soldAfter) {
                return 'Ye product delete nahi ho sakta: is PO ke stock receive hone ke baad is product ki sale ho chuki hai.';
            }
        }

        // Stock-sufficiency check (same guard as whole-PO delete): if
        // current stock is below what this line received, part of this
        // line's stock must already have been consumed.
        $product = $item->product;
        if ($product && (float) $item->received_quantity > 0 && $product->stock < (float) $item->received_quantity) {
            return "Ye product delete nahi ho sakta: stock me sirf {$product->stock} hai, jabke is PO se {$item->received_quantity} aaya tha — kuch sale ho chuka hai.";
        }

        return null;
    }

    /** Recomputes subtotal/discount_amount/total from every line's current
     *  RECEIVED quantity (not ordered) × its net cost — this correction
     *  screen exists to reconcile the PO's total with what actually came
     *  in, same reasoning as updateItem()'s line_total above. */
    private function recalculateTotals(PurchaseOrder $purchaseOrder): void
    {
        $subtotal = $purchaseOrder->items->sum(fn ($item) => $item->received_quantity * $item->net_cost_price);
        $discountAmount = round($subtotal * ($purchaseOrder->discount_percent / 100), 2);
        $purchaseOrder->update([
            'subtotal' => round($subtotal, 2),
            'discount_amount' => $discountAmount,
            'total' => round($subtotal - $discountAmount, 2),
        ]);
    }
}
