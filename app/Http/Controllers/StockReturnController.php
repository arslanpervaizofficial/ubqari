<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Models\StockReturn;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockReturnController extends Controller
{
    public function index(Request $request)
    {
        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());

        $customerReturns = StockReturn::with('product', 'customer', 'user')
            ->where('type', 'from_customer')
            ->whereBetween('created_at', [$from, $to . ' 23:59:59'])
            ->latest()
            ->paginate(15, ['*'], 'customer_page');

        $supplierReturns = StockReturn::with('product', 'supplier', 'user')
            ->where('type', 'to_supplier')
            ->whereBetween('created_at', [$from, $to . ' 23:59:59'])
            ->latest()
            ->paginate(15, ['*'], 'supplier_page');

        $customerReturnsTotal = StockReturn::where('type', 'from_customer')
            ->whereBetween('created_at', [$from, $to . ' 23:59:59'])
            ->selectRaw('SUM(quantity * unit_price - discount_amount) as total')->value('total') ?? 0;

        $supplierReturnsTotal = StockReturn::where('type', 'to_supplier')
            ->whereBetween('created_at', [$from, $to . ' 23:59:59'])
            ->selectRaw('SUM(quantity * unit_price - discount_amount) as total')->value('total') ?? 0;

        return view('stock_returns.index', compact(
            'customerReturns', 'supplierReturns', 'customerReturnsTotal', 'supplierReturnsTotal', 'from', 'to'
        ));
    }

    public function customerForm()
    {
        $products = Product::active()->orderBy('name')->get();
        $customers = Customer::orderBy('name')->get();

        // Same idea as PurchaseOrderController::create()'s $productsForJs — a
        // plain array the Blade file just echoes, so the product-search
        // dropdown has a local list to filter through before the user has
        // typed enough for the AJAX search to kick in.
        $productsForJs = $products->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'sku' => $p->sku,
            'price' => $p->sale_price,
            'stock' => $p->stock,
            'unit' => $p->unit,
        ])->values();

        return view('stock_returns.customer', compact('products', 'customers', 'productsForJs'));
    }

    /** Powers the "Load Previous Order" box on the Return from Customer
     *  screen — same idea as the order-number lookup already used on the
     *  Billing screen, except this one is read-only: it never touches the
     *  order or its stock, it just hands back what was actually sold (with
     *  its original discount) so the return form can be pre-filled from it
     *  instead of the discount having to be re-typed from memory. */
    public function lookupOrder(Request $request)
    {
        $data = $request->validate(['order_number' => ['required', 'string']]);
        $term = trim($data['order_number']);

        $order = Order::where('order_number', $term)
            ->orWhere('id', is_numeric($term) ? (int) $term : 0)
            ->where('status', 'completed')
            ->with(['items.product', 'customer'])
            ->first();

        if (!$order) {
            return response()->json(['error' => 'No completed order found with that order number/ID.'], 404);
        }

        $items = $order->items->map(function (OrderItem $item) use ($order) {
            $remaining = $item->remainingReturnableQuantity();
            if ($remaining <= 0 || !$item->product) {
                return null;
            }

            // A single discount % the return line can show: the item's own
            // line discount plus its share of the order-level overall
            // discount (which is applied as one flat % across every item's
            // after-line-discount subtotal — see recalculateTotals() in
            // POSController), collapsed into one effective percentage so the
            // return form only needs one discount field per line, the same
            // as a manually-added one.
            $netPerUnitAfterOrderDiscount = $item->quantity > 0
                ? ($item->line_total / $item->quantity) * (1 - $order->discount_percent / 100)
                : 0;
            $effectiveDiscountPercent = $item->unit_price > 0
                ? round(100 * (1 - ($netPerUnitAfterOrderDiscount / $item->unit_price)), 2)
                : 0;

            return [
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'name' => $item->product->name,
                'sku' => $item->product->sku,
                'unit' => $item->product->unit,
                'stock' => $item->product->stock,
                'unit_price' => $item->unit_price,
                'discount_percent' => max(0, min(100, $effectiveDiscountPercent)),
                // Sent separately purely so the return form can show the
                // shopkeeper *why* the combined % is what it is (item-level
                // discount vs. the overall discount applied at checkout) —
                // the combined 'discount_percent' above is what's actually
                // submitted and applied to stock/refund.
                'item_discount_percent' => (float) $item->discount_percent,
                'order_discount_percent' => (float) $order->discount_percent,
                'purchased_qty' => $item->quantity,
                'remaining_qty' => $remaining,
            ];
        })->filter()->values();

        if ($items->isEmpty()) {
            return response()->json(['error' => "Order {$order->order_number} has nothing left that hasn't already been returned."], 422);
        }

        return response()->json([
            'order_number' => $order->order_number,
            'customer_id' => $order->customer_id,
            'customer_name' => $order->customer->name ?? null,
            'items' => $items,
        ]);
    }

    /** Customer returns goods: stock goes UP, and if they owed money on it,
     *  their credit balance goes DOWN (a credit-note style adjustment).
     *  Accepts one or more line items in a single submission so a customer
     *  returning several different products doesn't need a separate form
     *  submission for each one. Each line is either tied to an order_item
     *  (loaded via lookupOrder(), discount carried over from the original
     *  sale) or a manually-added product with its own typed-in discount. */
    public function customerStore(Request $request)
    {
        $data = $request->validate([
            'customer_id' => ['nullable', 'exists:customers,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.order_item_id' => ['nullable', 'exists:order_items,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.reason' => ['nullable', 'string', 'max:255'],
        ]);

        // A line loaded from a real order must never return more than what's
        // actually still outstanding on that order item — re-checked here
        // against the database, not just the number the browser sent, in
        // case two return submissions for the same order overlapped.
        foreach ($data['items'] as $line) {
            if (!empty($line['order_item_id'])) {
                $orderItem = OrderItem::find($line['order_item_id']);
                $remaining = $orderItem ? $orderItem->remainingReturnableQuantity() : 0;
                if ($line['quantity'] > $remaining) {
                    return back()->withInput()->withErrors([
                        'quantity' => "{$orderItem?->product?->name}: only {$remaining} {$orderItem?->product?->unit} left returnable from that order — the rest was already returned.",
                    ]);
                }
            }
        }

        DB::transaction(function () use ($data) {
            $customer = !empty($data['customer_id']) ? Customer::find($data['customer_id']) : null;

            foreach ($data['items'] as $line) {
                $product = Product::findOrFail($line['product_id']);
                $product->increment('stock', $line['quantity']);

                $discountPercent = $line['discount_percent'] ?? 0;
                $gross = $line['quantity'] * $line['unit_price'];
                $discountAmount = round($gross * ($discountPercent / 100), 2);
                $netAmount = round($gross - $discountAmount, 2);

                $stockReturn = StockReturn::create([
                    'type' => 'from_customer',
                    'product_id' => $product->id,
                    'customer_id' => $data['customer_id'] ?? null,
                    'order_item_id' => $line['order_item_id'] ?? null,
                    'order_id' => !empty($line['order_item_id']) ? OrderItem::find($line['order_item_id'])?->order_id : null,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'discount_percent' => $discountPercent,
                    'discount_amount' => $discountAmount,
                    'reason' => $line['reason'] ?? null,
                    'user_id' => auth()->id(),
                ]);

                StockMovement::create([
                    'product_id' => $product->id,
                    'type' => 'adjustment',
                    'quantity' => $line['quantity'],
                    'reason' => 'Customer return: ' . ($line['reason'] ?? 'no reason given'),
                    'user_id' => auth()->id(),
                    'stock_return_id' => $stockReturn->id,
                ]);

                // If tied to a customer, reduce what they owe as a credit
                // note — by the NET amount (after this line's discount),
                // never the full undiscounted price. $customer->credit_balance
                // reflects each prior line's decrement already (Eloquent
                // updates the in-memory attribute), so a second line for the
                // same customer never over-refunds beyond what they actually
                // still owe.
                if ($customer) {
                    $refundAmount = min($netAmount, $customer->credit_balance);
                    if ($refundAmount > 0) {
                        $customer->payments()->create([
                            'type' => 'credit',
                            'amount' => $refundAmount,
                            'note' => 'Stock return: ' . $product->name . ' x' . $line['quantity'],
                            'user_id' => auth()->id(),
                        ]);
                        $customer->decrement('credit_balance', $refundAmount);
                    }
                }
            }
        });

        return redirect()->route('stock-returns.index')->with('status', 'Customer stock return recorded — stock updated.');
    }

    public function supplierForm()
    {
        $products = Product::active()->orderBy('name')->get();
        $suppliers = Supplier::orderBy('name')->get();

        $productsForJs = $products->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'sku' => $p->sku,
            'price' => $p->purchase_price,
            'stock' => $p->stock,
            'unit' => $p->unit,
        ])->values();

        return view('stock_returns.supplier', compact('products', 'suppliers', 'productsForJs'));
    }

    /** Powers the "Load Previous Purchase Order" box on the Return to
     *  Supplier screen — mirrors lookupOrder() on the customer side. Reads
     *  a received PO's lines (using received_quantity, not the originally
     *  ordered quantity, since suppliers often short/over-ship) with their
     *  original cost price and discount, purely for display/pre-fill —
     *  never touches the PO or stock itself. */
    public function lookupPurchaseOrder(Request $request)
    {
        $data = $request->validate(['po_id' => ['required']]);
        $term = trim((string) $data['po_id']);
        // Accept either the bare number or something like "PO #12" / "PO-12".
        preg_match('/(\d+)/', $term, $m);
        $id = $m[1] ?? null;

        $po = $id
            ? PurchaseOrder::where('id', $id)->where('status', 'received')->with(['items.product', 'supplier'])->first()
            : null;

        if (!$po) {
            return response()->json(['error' => 'No received purchase order found with that ID.'], 404);
        }

        $items = $po->items->map(function (PurchaseOrderItem $item) use ($po) {
            $remaining = $item->remainingReturnableQuantity();
            if ($remaining <= 0 || !$item->product) {
                return null;
            }

            // Combine this item's own discount with its share of the PO's
            // overall discount (a flat % over the whole after-line-discount
            // subtotal — see store() above) into one effective percentage,
            // the same collapsing lookupOrder() does for sales.
            $netPerUnitAfterOverallDiscount = $item->quantity > 0
                ? ($item->line_total / $item->quantity) * (1 - $po->discount_percent / 100)
                : 0;
            $effectiveDiscountPercent = $item->cost_price > 0
                ? round(100 * (1 - ($netPerUnitAfterOverallDiscount / $item->cost_price)), 2)
                : 0;

            return [
                'purchase_order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'name' => $item->product->name,
                'sku' => $item->product->sku,
                'unit' => $item->product->unit,
                'stock' => $item->product->stock,
                'unit_price' => $item->cost_price,
                'discount_percent' => max(0, min(100, $effectiveDiscountPercent)),
                'item_discount_percent' => (float) $item->discount_percent,
                'order_discount_percent' => (float) $po->discount_percent,
                'received_qty' => $item->received_quantity,
                'remaining_qty' => $remaining,
            ];
        })->filter()->values();

        if ($items->isEmpty()) {
            return response()->json(['error' => "PO #{$po->id} has nothing left that hasn't already been returned."], 422);
        }

        return response()->json([
            'po_id' => $po->id,
            'supplier_id' => $po->supplier_id,
            'supplier_name' => $po->supplier->name ?? null,
            'items' => $items,
        ]);
    }

    /** We return goods TO a supplier: our stock goes DOWN, and — same fix as
     *  the customer side — the return is credited at its NET value (after
     *  whatever discount applied on the original purchase), not the full
     *  undiscounted cost. Each line is either tied to a purchase_order_item
     *  (loaded via lookupPurchaseOrder(), discount carried over from the
     *  original purchase) or a manually-added product with its own
     *  typed-in discount. Accepts multiple line items per submission. */
    public function supplierStore(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.purchase_order_item_id' => ['nullable', 'exists:purchase_order_items,id'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.reason' => ['nullable', 'string', 'max:255'],
        ]);

        // Validate stock availability for every line — combining quantities
        // per product first — before touching anything, so a problem with
        // one line (or the same product appearing on two lines) never
        // leaves earlier lines already applied while a later one fails.
        $requiredByProduct = [];
        foreach ($data['items'] as $line) {
            $requiredByProduct[$line['product_id']] = ($requiredByProduct[$line['product_id']] ?? 0) + $line['quantity'];
        }
        foreach ($requiredByProduct as $productId => $qty) {
            $product = Product::find($productId);
            if ($qty > $product->stock) {
                return back()->withInput()->withErrors([
                    'quantity' => "{$product->name}: only {$product->stock} {$product->unit} in stock — can't return {$qty}.",
                ]);
            }
        }

        // A line loaded from a real PO must never return more than what's
        // actually still outstanding on that PO item — re-checked here
        // against the database, not just the number the browser sent.
        foreach ($data['items'] as $line) {
            if (!empty($line['purchase_order_item_id'])) {
                $poItem = PurchaseOrderItem::find($line['purchase_order_item_id']);
                $remaining = $poItem ? $poItem->remainingReturnableQuantity() : 0;
                if ($line['quantity'] > $remaining) {
                    return back()->withInput()->withErrors([
                        'quantity' => "{$poItem?->product?->name}: only {$remaining} {$poItem?->product?->unit} left returnable from that PO — the rest was already returned.",
                    ]);
                }
            }
        }

        DB::transaction(function () use ($data) {
            foreach ($data['items'] as $line) {
                $product = Product::findOrFail($line['product_id']);
                $product->decrement('stock', $line['quantity']);

                $discountPercent = $line['discount_percent'] ?? 0;
                $gross = $line['quantity'] * $line['unit_price'];
                $discountAmount = round($gross * ($discountPercent / 100), 2);

                $poItem = !empty($line['purchase_order_item_id']) ? PurchaseOrderItem::find($line['purchase_order_item_id']) : null;

                $stockReturn = StockReturn::create([
                    'type' => 'to_supplier',
                    'product_id' => $product->id,
                    'supplier_id' => $data['supplier_id'],
                    'purchase_order_item_id' => $line['purchase_order_item_id'] ?? null,
                    'purchase_order_id' => $poItem?->purchase_order_id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'discount_percent' => $discountPercent,
                    'discount_amount' => $discountAmount,
                    'reason' => $line['reason'] ?? null,
                    'user_id' => auth()->id(),
                ]);

                StockMovement::create([
                    'product_id' => $product->id,
                    'type' => 'adjustment',
                    'quantity' => -$line['quantity'],
                    'reason' => 'Returned to supplier: ' . ($line['reason'] ?? 'no reason given'),
                    'user_id' => auth()->id(),
                    'stock_return_id' => $stockReturn->id,
                ]);
            }
        });

        return redirect()->route('stock-returns.index')->with('status', 'Supplier stock return recorded — stock and purchase report updated.');
    }

    /** Moves a stock return to Trash — reverses the stock effect it had
     *  first (same principle as deleting a Sales Order or Purchase Order),
     *  so stock stays accurate while it's sitting in Trash. Restoring it
     *  from Trash puts that effect back. Admin only (see routes/web.php). */
    public function destroy(StockReturn $stockReturn)
    {
        DB::transaction(function () use ($stockReturn) {
            $stockReturn->reverseEffects();
            $stockReturn->delete();
        });

        return back()->with('status', 'Stock return moved to Trash — stock adjusted back.');
    }

    /** Bulk version of destroy() for the checked rows (works across both
     *  the Customer Returns and Supplier Returns tables on this page). */
    public function destroySelected(Request $request)
    {
        $ids = (array) $request->input('ids', []);
        $items = StockReturn::whereIn('id', $ids)->get();

        DB::transaction(function () use ($items) {
            foreach ($items as $item) {
                $item->reverseEffects();
                $item->delete();
            }
        });

        return back()->with('status', count($items) . ' stock return(s) moved to Trash — stock adjusted back.');
    }

    /** Deletes every return of the given type ("from_customer" or
     *  "to_supplier") within the currently-viewed date range — mirrors the
     *  from/to filter used by index(), so "Delete All" removes exactly
     *  what's on screen for that section. */
    public function destroyAll(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', 'in:from_customer,to_supplier'],
        ]);

        $from = $request->input('from', now()->startOfMonth()->toDateString());
        $to = $request->input('to', now()->toDateString());

        $items = StockReturn::where('type', $data['type'])
            ->whereBetween('created_at', [$from, $to . ' 23:59:59'])
            ->get();

        DB::transaction(function () use ($items) {
            foreach ($items as $item) {
                $item->reverseEffects();
                $item->delete();
            }
        });

        return back()->with('status', count($items) . ' stock return(s) moved to Trash — stock adjusted back.');
    }
}
