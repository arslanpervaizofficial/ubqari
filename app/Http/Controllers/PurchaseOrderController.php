<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    public function index()
    {
        $purchaseOrders = PurchaseOrder::with('supplier')->latest()->paginate(20);
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

        return view('purchase_orders.create', compact('suppliers', 'products', 'productsForJs', 'preselectedLines'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.cost_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.max_discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

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
                $line['discount_percent'] = $line['discount_percent'] ?? 0;

                // Max Discount % isn't a Purchase Order attribute at all —
                // it's the product's own billing-discount ceiling, just
                // shown and editable right here for convenience. Pull it
                // out before creating the PO item (it's not a column on
                // purchase_order_items) and push it onto the product
                // instead, same as Rate/Discount already update on receive.
                $maxDiscount = $line['max_discount_percent'] ?? null;
                unset($line['max_discount_percent']);

                $po->items()->create($line);

                if ($maxDiscount !== null) {
                    Product::where('id', $line['product_id'])->update(['max_discount_percent' => $maxDiscount]);
                }

                $netCost = $line['cost_price'] * (1 - $line['discount_percent'] / 100);
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
     *  (not necessarily what was ordered) and logs a purchase movement per line. */
    public function receive(Request $request, PurchaseOrder $purchaseOrder)
    {
        if ($purchaseOrder->status === 'received') {
            return back()->withErrors(['error' => 'This PO was already received.']);
        }

        $data = $request->validate([
            'items' => ['required', 'array'],
            'items.*.received_quantity' => ['required', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($purchaseOrder, $data) {
            foreach ($purchaseOrder->items()->with('product')->get() as $item) {
                $receivedQty = (float) ($data['items'][$item->id]['received_quantity'] ?? $item->quantity);

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
                    $item->product->update([
                        'purchase_price' => $item->cost_price,
                        'purchase_discount_percent' => $item->discount_percent,
                    ]);

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
                }
            }
            $purchaseOrder->update(['status' => 'received']);

            // Realign subtotal/total with what was ACTUALLY received right
            // away, rather than leaving the ordered-quantity figures stored
            // at store() time sitting there until someone happens to open
            // this PO's show page (the only other place this ran before).
            // Left un-recalculated, any PO received with a short/over-ship
            // silently kept a wrong `total`, which the Purchase Report reads
            // straight off the row — throwing it out of sync with the
            // Capital Report's Total Investment, which is computed live
            // from received_quantity and was never wrong to begin with.
            $this->recalculateTotals($purchaseOrder->fresh());
        });

        return redirect()->route('purchase-orders.index')->with('status', 'PO marked received — stock updated with actual received quantities.');
    }

    /** Read-only-looking but click-to-edit view of a received PO. Only
     *  three things are ever editable here — Received Qty ("stock" that
     *  came in), each line's own Discount % ("supplier discount"), and the
     *  PO's overall Discount % (the blanket discount, separate from any one
     *  line's own) — never cost_price or which products are on the order,
     *  since those aren't corrections so much as a different purchase
     *  entirely. Pending (not yet received) POs use the existing
     *  create/receive flow instead; this is purely for fixing a mistake
     *  noticed after the fact on one already marked received. */
    public function show(PurchaseOrder $purchaseOrder)
    {
        abort_unless($purchaseOrder->status === 'received', 404);
        $purchaseOrder->load('items.product', 'supplier');
        // Realigns subtotal/total with what was actually received — at
        // store() time these were computed off the ORDERED quantities, so
        // a PO with a short/over-shipment could otherwise show line totals
        // here that don't sum to the PO's own stored subtotal until the
        // first edit is made. Recalculating on every view keeps it
        // consistent from the very first look, not just after a correction.
        $this->recalculateTotals($purchaseOrder);
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

        DB::transaction(function () use ($item, $data) {
            if (array_key_exists('received_quantity', $data) && $data['received_quantity'] !== null) {
                $oldQty = $item->received_quantity;
                $newQty = $data['received_quantity'];
                $delta = $newQty - $oldQty;

                if ($delta !== 0.0) {
                    $item->product()->increment('stock', $delta);
                    StockMovement::create([
                        'product_id' => $item->product_id,
                        'type' => 'purchase',
                        'quantity' => $delta,
                        'reason' => "PO #{$item->purchase_order_id} received quantity corrected ({$oldQty} → {$newQty})",
                        'user_id' => auth()->id(),
                        'purchase_order_id' => $item->purchase_order_id,
                    ]);
                }

                $item->update(['received_quantity' => $newQty]);
            }

            if (array_key_exists('discount_percent', $data) && $data['discount_percent'] !== null) {
                $item->update(['discount_percent' => $data['discount_percent']]);
            }

            // Keep the product's cost reference (gross Purchase Price +
            // Purchase Discount %) in sync with this line's current
            // numbers, same as receive() does originally.
            $item->product->update([
                'purchase_price' => $item->fresh()->cost_price,
                'purchase_discount_percent' => $item->fresh()->discount_percent,
            ]);
        });

        $item->refresh();
        $purchaseOrder->refresh();
        $this->recalculateTotals($purchaseOrder);

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

        $purchaseOrder->update(['discount_percent' => $data['discount_percent']]);
        $this->recalculateTotals($purchaseOrder);

        return response()->json([
            'discount_percent' => $purchaseOrder->discount_percent,
            'subtotal' => $purchaseOrder->subtotal,
            'discount_amount' => $purchaseOrder->discount_amount,
            'total' => $purchaseOrder->total,
        ]);
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
