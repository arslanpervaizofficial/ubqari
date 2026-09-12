<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\PurchaseOrder;
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
        DB::transaction(function () use ($purchaseOrder) {
            $purchaseOrder->reverseReceivedEffects();
            $purchaseOrder->delete();
        });
        return back()->with('status', "PO #{$purchaseOrder->id} moved to Trash — stock adjusted.");
    }

    /** Bulk version of destroy() for the checked rows on the PO list. */
    public function destroySelected(Request $request)
    {
        $ids = (array) $request->input('ids', []);
        $purchaseOrders = PurchaseOrder::whereIn('id', $ids)->get();

        DB::transaction(function () use ($purchaseOrders) {
            foreach ($purchaseOrders as $purchaseOrder) {
                $purchaseOrder->reverseReceivedEffects();
                $purchaseOrder->delete();
            }
        });

        return back()->with('status', count($purchaseOrders) . ' purchase order(s) moved to Trash — stock adjusted.');
    }

    /** Bulk version of destroy() for every purchase order currently listed
     *  (this list has no search/filter yet, so "all" really means all). */
    public function destroyAll()
    {
        $purchaseOrders = PurchaseOrder::all();

        DB::transaction(function () use ($purchaseOrders) {
            foreach ($purchaseOrders as $purchaseOrder) {
                $purchaseOrder->reverseReceivedEffects();
                $purchaseOrder->delete();
            }
        });

        return back()->with('status', count($purchaseOrders) . ' purchase order(s) moved to Trash — stock adjusted.');
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
                    // keep purchase_price up to date with the latest NET cost (after this line's discount)
                    $item->product->update(['purchase_price' => $item->net_cost_price]);

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
        });

        return redirect()->route('purchase-orders.index')->with('status', 'PO marked received — stock updated with actual received quantities.');
    }
}
