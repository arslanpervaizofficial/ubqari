<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockMovement;
use App\Models\StockReturn;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
     *  (loaded via lookupOrder(); v3.1.0 H10: linked lines are priced
     *  from the SOURCE order item, submitted prices ignored) or a
     *  manually-added product with its own typed-in discount. */
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

        // v3.1.0 (H9): EVERY quantity/belonging check below runs
        // INSIDE the transaction on lockForUpdate rows (order items,
        // orders, products, customer), and remaining is recomputed
        // after locking — the old pre-transaction checks read stale
        // values, so two overlapping submissions could both pass them
        // and over-return. A rejection throws ValidationException,
        // which rolls the transaction back and redirects back with
        // errors (same UX as the old withErrors returns). Quantities
        // are aggregated per order item first, so the same item on
        // two lines cannot over-return in one submission. Over-returns
        // are REJECTED, never clamped.
        DB::transaction(function () use ($data) {
            $customer = !empty($data['customer_id'])
                ? Customer::lockForUpdate()->find($data['customer_id'])
                : null;

            $linkedItemIds = collect($data['items'])
                ->pluck('order_item_id')->filter()->unique()->sort()->values()->all();
            $orderItems = OrderItem::whereIn('id', $linkedItemIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $orderIds = $orderItems->pluck('order_id')->unique()->sort()->values()->all();
            $orders = Order::whereIn('id', $orderIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $productIds = collect($data['items'])->pluck('product_id')->unique()->sort()->values()->all();
            $products = Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $requestedByItem = [];
            foreach ($data['items'] as $line) {
                if (!empty($line['order_item_id'])) {
                    $requestedByItem[$line['order_item_id']] = ($requestedByItem[$line['order_item_id']] ?? 0) + (float) $line['quantity'];
                }
            }

            foreach ($data['items'] as $line) {
                if (empty($line['order_item_id'])) {
                    continue;
                }
                $orderItem = $orderItems->get($line['order_item_id']);
                if (!$orderItem) {
                    throw ValidationException::withMessages(['quantity' => 'A return line names an order item that no longer exists.']);
                }
                $order = $orders->get($orderItem->order_id);
                if (!$order) {
                    throw ValidationException::withMessages(['quantity' => 'A return line names an order that is no longer available.']);
                }
                if ((int) $line['product_id'] !== (int) $orderItem->product_id) {
                    throw ValidationException::withMessages(['quantity' => "Product mismatch: this return line's product does not match the order item it was loaded from."]);
                }
                if ($order->status !== 'completed') {
                    throw ValidationException::withMessages(['quantity' => "Order {$order->order_number} is not completed, so nothing from it can be returned."]);
                }
                // Loose comparison on purpose: the form posts strings,
                // the order stores ints, and null (walk-in) must only
                // match null — null == 0 is false in PHP, so this is safe.
                if ($order->customer_id != ($data['customer_id'] ?? null)) {
                    throw ValidationException::withMessages(['quantity' => 'Customer mismatch: this return line belongs to a different customer than the one selected.']);
                }
            }
            foreach ($requestedByItem as $itemId => $requested) {
                $orderItem = $orderItems->get($itemId);
                $remaining = $orderItem->remainingReturnableQuantity();
                if ($requested > $remaining) {
                    $product = $products->get($orderItem->product_id);
                    throw ValidationException::withMessages(['quantity' => "{$product?->name}: only {$remaining} {$product?->unit} left returnable from that order — the rest was already returned."]);
                }
            }

            foreach ($data['items'] as $line) {
                $product = $products->get($line['product_id']) ?? Product::findOrFail($line['product_id']);
                $product->increment('stock', $line['quantity']);

                // v3.0 FEATURE 1 — customer returns go back into
                // batches: earliest-expiry partially-consumed batches
                // are topped up first (never above their original
                // quantity); any remainder — or the whole quantity when
                // the product has no batches at all — lands in an
                // "UNBATCHED" batch (created if needed). Supplier
                // returns mirror the other way (see supplierStore()).
                // restoreReturned() never throws, so a batch problem
                // can never fail a return (v3.1.0 H8: failures are
                // logged with context and surfaced after the txn).
                ProductBatch::restoreReturned((int) $product->id, (float) $line['quantity'], ['record' => 'Customer return']);

                // v3.1.0 (H10): a linked line uses the SOURCE order
                // item's price/discount — submitted values are ignored
                // for linked lines (the form pre-fills them from the
                // lookup, but a crafted POST must not reprice a
                // return). Typed values are allowed ONLY for unlinked
                // manual lines, which are flagged in the return note
                // (no dedicated flag column exists on stock_returns).
                $orderItem = !empty($line['order_item_id']) ? $orderItems->get($line['order_item_id']) : null;
                if ($orderItem) {
                    $unitPrice = (float) $orderItem->unit_price;
                    $discountPercent = (float) $orderItem->discount_percent;
                    $reason = $line['reason'] ?? null;
                } else {
                    $unitPrice = (float) $line['unit_price'];
                    $discountPercent = (float) ($line['discount_percent'] ?? 0);
                    $reason = trim(($line['reason'] ? $line['reason'] . ' ' : '') . '(manual price)');
                }
                $gross = $line['quantity'] * $unitPrice;
                $discountAmount = round($gross * ($discountPercent / 100), 2);
                $netAmount = round($gross - $discountAmount, 2);

                $stockReturn = StockReturn::create([
                    'type' => 'from_customer',
                    'product_id' => $product->id,
                    'customer_id' => $data['customer_id'] ?? null,
                    'order_item_id' => $line['order_item_id'] ?? null,
                    'order_id' => $orderItem?->order_id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $unitPrice,
                    'discount_percent' => $discountPercent,
                    'discount_amount' => $discountAmount,
                    'reason' => $reason,
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

                // v3.1.0 (H11): a linked return reduces the SOURCE
                // order's due_amount in this same transaction — named
                // AND walk-in orders alike — so the returned amount
                // cannot be collected again through the order ledger
                // (recordPayment caps credits at the due). The
                // customer-balance effect below is separate: the
                // ledger formula (Customer::ledgerFigures) derives the
                // balance from order totals/paid plus payments, not
                // from the due_amount column, so reducing the column
                // does not double-count the credit.
                if ($orderItem && $netAmount != 0) {
                    $orders->get($orderItem->order_id)?->decrement('due_amount', $netAmount);
                }

                // H6 — if tied to a customer, record the FULL net refund
                // (after this line's discount), never silently $0. The old
                // code capped the refund at credit_balance, so a customer
                // who had paid cash in full (balance 0) got no refund
                // record at all, and a partial balance made the excess
                // simply vanish. Now the whole net amount is credited and
                // credit_balance is allowed to go negative — that negative
                // balance IS the advance the shop owes the customer, and it
                // is visible in the ledger instead of invisible. The
                // payment row is linked to this return (stock_return_id)
                // so StockReturn::reverseEffects() can reverse exactly
                // this credit if the return is ever moved to Trash.
                // CustomerPayment's $fillable does not list
                // stock_return_id, so the link is set with forceFill()
                // after creation rather than through mass assignment.
                // The locked customer instance reflects each prior
                // line's decrement already, so multi-line returns
                // accumulate correctly.
                if ($customer && $netAmount > 0) {
                    $payment = $customer->payments()->create([
                        'type' => 'credit',
                        'amount' => $netAmount,
                        'note' => 'Stock return: ' . $product->name . ' x' . $line['quantity'],
                        'user_id' => auth()->id(),
                    ]);
                    $payment->forceFill(['stock_return_id' => $stockReturn->id])->save();
                    $customer->decrement('credit_balance', $netAmount);
                }
            }
        });

        // v3.1.0 (H8): batch failures never fail the return, but are
        // surfaced here (no dedicated 'warning' flash slot exists in
        // the layouts, so the warning rides on the status message).
        $batchError = ProductBatch::takeLastBatchError();
        $status = 'Customer stock return recorded — stock updated.'
            . ($batchError ? " Warning: {$batchError}" : '');

        return redirect()->route('stock-returns.index')->with('status', $status);
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

        // v3.1.0 (H9/M10): like customerStore(), ALL quantity,
        // belonging and stock-availability checks run INSIDE the
        // transaction on lockForUpdate rows, quantities aggregated per
        // product and per PO item first, and over-returns are REJECTED
        // (never clamped). The supplier balance sync also moves INSIDE
        // this transaction (M10), on the locked supplier row.
        DB::transaction(function () use ($data) {
            $supplier = Supplier::lockForUpdate()->find($data['supplier_id']);

            $linkedPoItemIds = collect($data['items'])
                ->pluck('purchase_order_item_id')->filter()->unique()->sort()->values()->all();
            $poItems = PurchaseOrderItem::whereIn('id', $linkedPoItemIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $poIds = $poItems->pluck('purchase_order_id')->unique()->sort()->values()->all();
            $pos = PurchaseOrder::whereIn('id', $poIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $productIds = collect($data['items'])->pluck('product_id')->unique()->sort()->values()->all();
            $products = Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            // Stock availability per product, combined across lines.
            $requiredByProduct = [];
            foreach ($data['items'] as $line) {
                $requiredByProduct[$line['product_id']] = ($requiredByProduct[$line['product_id']] ?? 0) + (float) $line['quantity'];
            }
            foreach ($requiredByProduct as $productId => $qty) {
                $product = $products->get($productId);
                if (!$product) {
                    throw ValidationException::withMessages(['quantity' => 'A return line names a product that no longer exists.']);
                }
                if ($qty > (float) $product->stock) {
                    throw ValidationException::withMessages(['quantity' => "{$product->name}: only {$product->stock} {$product->unit} in stock — can't return {$qty}."]);
                }
            }

            // Belonging + remaining per PO item, combined across lines.
            $requiredByPoItem = [];
            foreach ($data['items'] as $line) {
                if (!empty($line['purchase_order_item_id'])) {
                    $requiredByPoItem[$line['purchase_order_item_id']] = ($requiredByPoItem[$line['purchase_order_item_id']] ?? 0) + (float) $line['quantity'];
                }
            }
            foreach ($data['items'] as $line) {
                if (empty($line['purchase_order_item_id'])) {
                    continue;
                }
                $poItem = $poItems->get($line['purchase_order_item_id']);
                if (!$poItem) {
                    throw ValidationException::withMessages(['quantity' => 'A return line names a purchase order item that no longer exists.']);
                }
                $po = $pos->get($poItem->purchase_order_id);
                if (!$po) {
                    throw ValidationException::withMessages(['quantity' => 'A return line names a purchase order that is no longer available.']);
                }
                if ((int) $line['product_id'] !== (int) $poItem->product_id) {
                    throw ValidationException::withMessages(['quantity' => "Product mismatch: this return line's product does not match the purchase order item it was loaded from."]);
                }
                // Loose comparison on purpose: the form posts strings,
                // the PO stores ints.
                if ($po->supplier_id != $data['supplier_id']) {
                    throw ValidationException::withMessages(['quantity' => 'Supplier mismatch: this return line belongs to a different supplier than the one selected.']);
                }
                if ($po->status !== 'received') {
                    throw ValidationException::withMessages(['quantity' => "PO #{$po->id} is not received, so nothing from it can be returned."]);
                }
            }
            foreach ($requiredByPoItem as $poItemId => $requested) {
                $poItem = $poItems->get($poItemId);
                $remaining = $poItem->remainingReturnableQuantity();
                if ($requested > $remaining) {
                    $product = $products->get($poItem->product_id);
                    throw ValidationException::withMessages(['quantity' => "{$product?->name}: only {$remaining} {$product?->unit} left returnable from that PO — the rest was already returned."]);
                }
            }

            foreach ($data['items'] as $line) {
                $product = $products->get($line['product_id']) ?? Product::findOrFail($line['product_id']);
                $product->decrement('stock', $line['quantity']);

                // v3.1.0 (H7): a supplier return takes stock OUT, so
                // batches are consumed FIFO to match. (The old code
                // deliberately skipped batches here; that left total
                // batched remaining above stock by every returned
                // unit. Batch attribution for a correction-style
                // delta is FIFO, not the original PO batch — see
                // ProductBatch::mirrorStockDelta().)
                ProductBatch::mirrorStockDelta((int) $product->id, -(float) $line['quantity'], ['record' => 'Supplier return']);

                // v3.1.0 (H10): linked lines use the SOURCE PO line's
                // cost/discount; submitted values are ignored for
                // them. Typed values only for unlinked manual lines,
                // flagged in the return note (no flag column exists).
                $poItem = !empty($line['purchase_order_item_id']) ? $poItems->get($line['purchase_order_item_id']) : null;
                if ($poItem) {
                    $unitPrice = (float) $poItem->cost_price;
                    $discountPercent = (float) $poItem->discount_percent;
                    $reason = $line['reason'] ?? null;
                } else {
                    $unitPrice = (float) $line['unit_price'];
                    $discountPercent = (float) ($line['discount_percent'] ?? 0);
                    $reason = trim(($line['reason'] ? $line['reason'] . ' ' : '') . '(manual price)');
                }
                $gross = $line['quantity'] * $unitPrice;
                $discountAmount = round($gross * ($discountPercent / 100), 2);

                $stockReturn = StockReturn::create([
                    'type' => 'to_supplier',
                    'product_id' => $product->id,
                    'supplier_id' => $data['supplier_id'],
                    'purchase_order_item_id' => $line['purchase_order_item_id'] ?? null,
                    'purchase_order_id' => $poItem?->purchase_order_id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $unitPrice,
                    'discount_percent' => $discountPercent,
                    'discount_amount' => $discountAmount,
                    'reason' => $reason,
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

                // H8 — keep the PO invariant: a supplier return reduces
                // the PO line's received_quantity by the returned amount
                // and the PO totals are recomputed from the reduced
                // figures. Before this, the PO kept showing the full
                // received quantity and total after a return, which made
                // the whole-PO delete guard (stock < received) refuse
                // forever and made a Trash restore add the returned units
                // back a second time. Because received_quantity is now
                // the NET held quantity, reverse/restoreReceivedEffects()
                // — which both use received_quantity as-is — stay correct
                // with no change, and remainingReturnableQuantity() is
                // simply that net figure (see PurchaseOrderItem).
                if ($poItem) {
                    $poItem->decrement('received_quantity', $line['quantity']);
                    if ((float) $poItem->received_quantity < 0) {
                        throw ValidationException::withMessages(['quantity' => "Supplier return for \"{$product->name}\" exceeds what PO #{$poItem->purchase_order_id} still holds."]);
                    }
                    $this->recalculatePoTotals($pos->get($poItem->purchase_order_id));
                }
            }

            // v2.2 supplier ledger hook, now INSIDE the transaction
            // (M10): the return already reduced the PO line's
            // received_quantity and recomputed the PO total (H8), so
            // what we owe this supplier has fallen by the returned
            // value — re-sync it. Returns are NOT subtracted again in
            // syncBalance() itself, or every return would count twice.
            $supplier?->syncBalance();
        });

        // v3.1.0 (H8): see customerStore() — batch warning rides on
        // the status message (no 'warning' flash slot in the layouts).
        $batchError = ProductBatch::takeLastBatchError();
        $status = 'Supplier stock return recorded — stock and purchase report updated.'
            . ($batchError ? " Warning: {$batchError}" : '');

        return redirect()->route('stock-returns.index')->with('status', $status);
    }
    /** Recomputes a PO's subtotal/discount/total from its lines' current
     *  (net, post-return) received quantities — the same formula
     *  PurchaseOrderController::recalculateTotals() uses, duplicated here
     *  because that method is private to that controller. */
    private function recalculatePoTotals(PurchaseOrder $purchaseOrder): void
    {
        $subtotal = $purchaseOrder->items()->get()->sum(fn ($item) => $item->received_quantity * $item->net_cost_price);
        $discountAmount = round($subtotal * ($purchaseOrder->discount_percent / 100), 2);
        $purchaseOrder->update([
            'subtotal' => round($subtotal, 2),
            'discount_amount' => $discountAmount,
            'total' => round($subtotal - $discountAmount, 2),
        ]);
    }

    /** Moves a stock return to Trash — reverses the stock effect it had
     *  first (same principle as deleting a Sales Order or Purchase Order),
     *  so stock stays accurate while it's sitting in Trash. Restoring it
     *  from Trash puts that effect back. Admin only (see routes/web.php).
     *  M9 — a reverse that would drive stock negative throws in
     *  reverseEffects(); it is caught here and shown as a plain error
     *  instead of a 500 page, and the transaction rolls back so nothing
     *  is half-applied. */
    public function destroy(StockReturn $stockReturn)
    {
        try {
            DB::transaction(function () use ($stockReturn) {
                $stockReturn->reverseEffects();
                $stockReturn->delete();
            });
        } catch (\RuntimeException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return back()->with('status', 'Stock return moved to Trash — stock adjusted back.');
    }

    /** Bulk version of destroy() for the checked rows (works across both
     *  the Customer Returns and Supplier Returns tables on this page). */
    public function destroySelected(Request $request)
    {
        $ids = (array) $request->input('ids', []);
        $items = StockReturn::whereIn('id', $ids)->get();

        try {
            DB::transaction(function () use ($items) {
                foreach ($items as $item) {
                    $item->reverseEffects();
                    $item->delete();
                }
            });
        } catch (\RuntimeException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

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

        try {
            DB::transaction(function () use ($items) {
                foreach ($items as $item) {
                    $item->reverseEffects();
                    $item->delete();
                }
            });
        } catch (\RuntimeException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return back()->with('status', count($items) . ' stock return(s) moved to Trash — stock adjusted back.');
    }
}
