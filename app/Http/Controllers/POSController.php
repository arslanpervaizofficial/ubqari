<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class POSController extends Controller
{
    public function index(Request $request)
    {
        $order = $this->getOrCreateCurrentOrder($request);
        $order->load('items.product', 'customer');

        $heldOrders = Order::where('status', 'hold')
            ->where('user_id', auth()->id())
            ->with('customer')
            ->withCount('items')
            ->latest()
            ->get();

        $customers = Customer::where('is_active', true)->orderBy('name')->get();

        return view('pos.index', compact('order', 'heldOrders', 'customers'));
    }

    /** "Previous order id" field on the billing screen — loads a completed
     *  order back into the cart so it can be corrected and re-completed.
     *  Reverses its stock/ledger effects first (same as OrderController::edit)
     *  so re-completing it recounts stock and payment correctly instead of
     *  double-counting. */
    public function loadOrderForEdit(Request $request)
    {
        $data = $request->validate(['order_number' => ['required', 'string']]);

        $order = Order::where('order_number', trim($data['order_number']))
            ->orWhere('id', is_numeric($data['order_number']) ? (int) $data['order_number'] : 0)
            ->where('status', 'completed')
            ->first();

        if (!$order) {
            return back()->withErrors(['error' => 'No completed order found with that order number/ID.']);
        }

        DB::transaction(function () use ($order, $request) {
            $currentId = $request->session()->get('current_order_id');
            if ($currentId && $currentId != $order->id) {
                $current = Order::where('id', $currentId)->where('status', 'in_progress')->first();
                if ($current) {
                    $current->items()->count() > 0 ? $current->update(['status' => 'hold']) : $current->delete();
                }
            }

            $order->reverseCompletedEffects();
            $order->update(['status' => 'in_progress']);
        });

        $request->session()->put('current_order_id', $order->id);

        return redirect()->route('pos.index')->with('status', "Order {$order->order_number} loaded — edit and click Update Order.");
    }

    private function getOrCreateCurrentOrder(Request $request): Order
    {
        $orderId = $request->session()->get('current_order_id');

        if ($orderId) {
            $order = Order::where('id', $orderId)->where('status', 'in_progress')->first();
            if ($order) {
                return $order;
            }
        }

        // Safety net: this cashier had an in_progress order tracked by a
        // previous session (e.g. after auto-logout). Only preserve it as
        // "held" if it actually has items — an empty cart should never
        // clutter the Held Orders list. Orders normally only become Held
        // when the cashier explicitly clicks "+ New Order" or "Save as Draft".
        Order::where('user_id', auth()->id())
            ->where('status', 'in_progress')
            ->get()
            ->each(function ($stray) {
                if ($stray->items()->count() > 0) {
                    $stray->update(['status' => 'hold']);
                } else {
                    $stray->delete();
                }
            });

        $order = Order::create([
            'order_number' => 'ORD-' . now()->format('Ymd') . '-' . strtoupper(Str::random(5)),
            'user_id' => auth()->id(),
            'status' => 'in_progress',
        ]);

        $request->session()->put('current_order_id', $order->id);

        return $order;
    }

    public function newOrder(Request $request)
    {
        $currentId = $request->session()->get('current_order_id');
        if ($currentId) {
            $current = Order::where('id', $currentId)->where('status', 'in_progress')->first();
            if ($current) {
                if ($current->items()->count() > 0) {
                    $current->update(['status' => 'hold']);
                } else {
                    $current->delete();
                }
            }
        }

        $request->session()->forget('current_order_id');
        $order = $this->getOrCreateCurrentOrder($request);

        return redirect()->route('pos.index')->with('status', "New order {$order->order_number} started.");
    }

    public function heldIndex()
    {
        $query = Order::where('status', 'hold')->with('customer', 'cashier')->withCount('items');
        if (auth()->user()->role === 'cashier') {
            $query->where('user_id', auth()->id());
        }
        $heldOrders = $query->latest('updated_at')->paginate(20);

        return view('pos.held_index', compact('heldOrders'));
    }

    public function showHeld(Order $order)
    {
        if ($order->status !== 'hold') {
            abort(404);
        }
        $order->load('items.product', 'customer');
        return view('pos.held_detail', compact('order'));
    }

    public function resume(Request $request, Order $order)
    {
        if ($order->status !== 'hold') {
            return back()->withErrors(['error' => 'Only held orders can be resumed.']);
        }

        $currentId = $request->session()->get('current_order_id');
        if ($currentId && $currentId != $order->id) {
            $current = Order::where('id', $currentId)->where('status', 'in_progress')->first();
            if ($current) {
                if ($current->items()->count() > 0) {
                    $current->update(['status' => 'hold']);
                } else {
                    $current->delete();
                }
            }
        }

        $order->update(['status' => 'in_progress']);
        $request->session()->put('current_order_id', $order->id);

        return redirect()->route('pos.index')->with('status', "Resumed {$order->order_number}.");
    }

    public function addItem(Request $request)
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'quantity' => ['required', 'numeric', 'min:0.01'],
        ]);

        $order = $this->getOrCreateCurrentOrder($request);
        $product = Product::findOrFail($data['product_id']);

        // Round quantity according to unit type: whole numbers for piece/box/etc,
        // 2-decimal precision for kg/ml/etc.
        $qty = $product->roundQuantity((float) $data['quantity']);
        if ($qty <= 0) $qty = $product->roundQuantity(1);

        $existing = $order->items()->where('product_id', $product->id)->first();

        // Room left beyond everything already reserved (including this
        // item's own current quantity in this cart, if any) — see
        // availableStock() docblock. This is how much MORE can be added.
        $available = $this->availableStock($product);
        $warning = null;

        if ($qty > $available) {
            if ($available <= 0 && !$existing) {
                // Genuinely nothing to add — this is the one case still
                // blocked outright, since there's no sensible partial
                // quantity to fall back to.
                return response()->json(['error' => "{$product->name} is out of stock."], 422);
            }

            // Non-blocking: clamp to whatever's actually available and tell
            // the cashier, rather than rejecting the request. Blocking here
            // meant the warning only ever surfaced once (via the one-off
            // error alert) instead of every time the limit is hit again.
            $cappedTotal = ($existing->quantity ?? 0) + max(0, $available);
            $warning = "{$product->name}: only {$cappedTotal} {$product->unit} available in total. Quantity adjusted.";
            $qty = max(0, $product->roundQuantity($available));
        }

        if ($existing) {
            $newQty = $product->roundQuantity($existing->quantity + $qty);
            $existing->quantity = $newQty;
            $this->recalculateLineTotal($existing);
            $existing->save();
        } else {
            $item = new OrderItem([
                'product_id' => $product->id,
                'quantity' => $qty,
                'unit_price' => $product->sale_price,
                'discount_percent' => 0,
            ]);
            $this->recalculateLineTotal($item);
            $order->items()->save($item);
        }

        $this->recalculateTotals($order);

        return response()->json([
            'success' => true,
            'order' => $order->fresh('items.product', 'customer'),
            'available_stock' => $this->availableStock($product),
            'warning' => $warning,
        ]);
    }

    public function removeItem(Request $request, OrderItem $item)
    {
        $order = $item->order;
        $item->delete();
        $this->recalculateTotals($order);

        return response()->json(['success' => true, 'order' => $order->fresh('items.product', 'customer')]);
    }

    public function updateItemQuantity(Request $request, OrderItem $item)
    {
        $data = $request->validate(['quantity' => ['required', 'numeric', 'min:0.01']]);
        $product = $item->product;

        $qty = $product->roundQuantity((float) $data['quantity']);
        if ($qty <= 0) $qty = $product->roundQuantity(1);

        // Total this item's quantity is allowed to reach: room left beyond
        // everything reserved elsewhere, PLUS what this item already holds
        // (since that's counted as "reserved" too and would otherwise be
        // subtracted twice).
        $availableExcludingThis = $this->availableStock($product) + $item->quantity;

        $warning = null;
        if ($availableExcludingThis <= 0) {
            // Stock got used up elsewhere since this line was added — can't
            // increase it further, so leave it exactly as it is.
            $warning = "{$product->name}: no additional stock available.";
            $qty = $item->quantity;
        } elseif ($qty > $availableExcludingThis) {
            // Non-blocking: clamp to what's available and warn, instead of
            // rejecting the request outright. A hard error here only ever
            // surfaced once (as a one-off popup); clamping + warning fires
            // every single time the limit is hit, which is what's needed
            // for a cashier who keeps typing quantities above stock.
            $warning = "Only {$availableExcludingThis} {$product->unit} of {$product->name} available. Quantity adjusted.";
            $qty = $product->roundQuantity($availableExcludingThis);
        }

        $item->quantity = $qty;
        $this->recalculateLineTotal($item);
        $item->save();

        $this->recalculateTotals($item->order);

        return response()->json([
            'success' => true,
            'order' => $item->order->fresh('items.product', 'customer'),
            'warning' => $warning,
        ]);
    }

    /** Per-item discount. Applying above the product's max_discount_percent is
     *  ALLOWED (cashier can still do it) but flagged back to the UI as a warning
     *  so it stays visible as a tip, not a blocker. */
    public function updateItemDiscount(Request $request, OrderItem $item)
    {
        $data = $request->validate(['discount_percent' => ['required', 'numeric', 'min:0', 'max:100']]);

        $item->discount_percent = $data['discount_percent'];
        $this->recalculateLineTotal($item);
        $item->save();

        $this->recalculateTotals($item->order);

        $exceedsMax = $item->product->max_discount_percent > 0
            && $data['discount_percent'] > $item->product->max_discount_percent;

        return response()->json([
            'success' => true,
            'order' => $item->order->fresh('items.product', 'customer'),
            'warning' => $exceedsMax
                ? "{$item->product->name}: discount {$data['discount_percent']}% exceeds max allowed ({$item->product->max_discount_percent}%). Applied anyway — adjust if needed."
                : null,
        ]);
    }

    /** Overall order-level discount, editable independently of the customer's default. */
    public function setOverallDiscount(Request $request)
    {
        $order = $this->getOrCreateCurrentOrder($request);
        $data = $request->validate(['discount_percent' => ['required', 'numeric', 'min:0', 'max:100']]);

        $order->discount_percent = $data['discount_percent'];
        $order->save();
        $this->recalculateTotals($order);

        return response()->json(['success' => true, 'order' => $order->fresh('items.product', 'customer')]);
    }

    public function setCustomer(Request $request)
    {
        $order = $this->getOrCreateCurrentOrder($request);
        $data = $request->validate(['customer_id' => ['nullable', 'exists:customers,id']]);

        $order->customer_id = $data['customer_id'] ?? null;
        // Suggest the customer's default discount as the overall discount —
        // cashier can still edit it afterwards via setOverallDiscount.
        $order->discount_percent = $order->customer_id
            ? (Customer::find($order->customer_id)->discount_percent ?? 0)
            : 0;
        $order->save();
        $this->recalculateTotals($order);

        return response()->json(['success' => true, 'order' => $order->fresh('items.product', 'customer')]);
    }

    private function availableStock(Product $product): float
    {
        $reserved = OrderItem::where('product_id', $product->id)
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['in_progress', 'hold']))
            ->sum('quantity');

        return $product->roundQuantity($product->stock - $reserved);
    }

    private function recalculateLineTotal(OrderItem $item): void
    {
        $gross = $item->quantity * $item->unit_price;
        $item->discount_amount = round($gross * ($item->discount_percent / 100), 2);
        $item->line_total = round($gross - $item->discount_amount, 2);
    }

    private function recalculateTotals(Order $order): void
    {
        $items = $order->items;
        $grossSubtotal = $items->sum(fn ($i) => $i->quantity * $i->unit_price);
        $lineDiscountTotal = $items->sum('discount_amount');
        $afterLineDiscounts = $grossSubtotal - $lineDiscountTotal;

        $overallDiscountAmount = round($afterLineDiscounts * ($order->discount_percent / 100), 2);
        $total = $afterLineDiscounts - $overallDiscountAmount;

        $order->update([
            'subtotal' => $grossSubtotal,
            'line_discount_total' => $lineDiscountTotal,
            'discount_amount' => $overallDiscountAmount,
            'total' => $total,
            'due_amount' => max(0, $total - $order->paid_amount),
        ]);
    }

    public function complete(Request $request, Order $order)
    {
        $data = $request->validate([
            'payment_method' => ['required', 'in:cash,bank,split'],
            'cash_amount' => ['nullable', 'numeric', 'min:0'],
            'bank_amount' => ['nullable', 'numeric', 'min:0'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'bank_name' => ['required_if:payment_method,bank,split', 'nullable', 'string', 'max:255'],
            'transaction_id' => ['required_if:payment_method,bank,split', 'nullable', 'string', 'max:255'],
        ]);

        if ($order->items()->count() === 0) {
            return back()->withErrors(['error' => 'Cannot complete an empty order.']);
        }

        DB::transaction(function () use ($order, $data) {
            foreach ($order->items()->with('product')->get() as $item) {
                $item->product->decrement('stock', $item->quantity);
                StockMovement::create([
                    'product_id' => $item->product_id,
                    'type' => 'sale',
                    'quantity' => -$item->quantity,
                    'user_id' => auth()->id(),
                    'order_id' => $order->id,
                ]);
            }

            $paid = $data['payment_method'] === 'split'
                ? (float) ($data['cash_amount'] ?? 0) + (float) ($data['bank_amount'] ?? 0)
                : (float) ($data['paid_amount'] ?? $order->total);

            $due = max(0, $order->total - $paid);

            $order->update([
                'status' => 'completed',
                // A held/draft order that gets completed and paid for is a
                // real sale now, not a quotation/estimate anymore — reset
                // this here so the printed invoice shows "INVOICE" instead
                // of still saying "QUOTATION / ESTIMATE" (is_quotation was
                // only ever set to true by Save as Draft/Quotation, the
                // same button used to just hold an order for later, and
                // was never cleared again once that order was resumed and
                // actually completed).
                'is_quotation' => false,
                'payment_method' => $data['payment_method'],
                'bank_name' => $data['bank_name'] ?? null,
                'transaction_id' => $data['transaction_id'] ?? null,
                'paid_amount' => $paid,
                'due_amount' => $due,
                // Set once, on the very first completion, and never touched
                // again — even when this same order is later reopened and
                // re-completed through the "load order to edit" flow.
                'original_completed_at' => $order->original_completed_at ?? now(),
            ]);

            if ($order->customer_id && $due > 0) {
                $order->customer()->increment('credit_balance', $due);
            }
        });

        request()->session()->forget('current_order_id');

        return redirect()->route('pos.invoice', $order)->with('status', 'Order completed.');
    }

    public function saveAsQuotation(Request $request, Order $order)
    {
        $order->update(['is_quotation' => true, 'status' => 'hold']);
        request()->session()->forget('current_order_id');

        return redirect()->route('pos.invoice', $order)->with('status', 'Saved as quotation/draft.');
    }

    /** "Delete" on a Held Order — it was never completed (no stock
     *  deducted, no payment recorded), so there's nothing to reverse; just
     *  move it to Trash like every other deletable record in the app.
     *  This used to instead set status='cancelled' and leave the row in
     *  place permanently — with no stock/ledger effects to undo that was
     *  harmless in itself, but it left the order in a dead-end state: not
     *  in Held Orders anymore, not completed, and with no Delete button
     *  available on the main Orders list either (that page only shows
     *  Update/Delete for 'completed' rows) — so it just sat there
     *  forever with no way to get rid of it, and still showed up in a
     *  customer's Ledger order history despite representing a sale that
     *  never actually happened. */
    public function cancel(Request $request, Order $order)
    {
        $order->delete();
        if ($request->session()->get('current_order_id') == $order->id) {
            $request->session()->forget('current_order_id');
        }
        return redirect()->route('pos.held-index')->with('status', 'Held order moved to Trash.');
    }

    public function invoice(Order $order)
    {
        $order->load('items.product', 'customer', 'cashier');
        return view('pos.invoice', compact('order'));
    }

    public function reorder(Request $request, Order $order)
    {
        $newOrder = $this->getOrCreateCurrentOrder($request);
        foreach ($order->items as $sourceItem) {
            $product = $sourceItem->product;
            $available = $this->availableStock($product);
            $qty = min($sourceItem->quantity, $available);
            if ($qty <= 0) continue;

            $item = new OrderItem([
                'product_id' => $product->id,
                'quantity' => $product->roundQuantity($qty),
                'unit_price' => $product->sale_price,
                'discount_percent' => $sourceItem->discount_percent,
            ]);
            $this->recalculateLineTotal($item);
            $newOrder->items()->save($item);
        }
        $newOrder->customer_id = $order->customer_id;
        $newOrder->save();
        $this->recalculateTotals($newOrder);

        return redirect()->route('pos.index')->with('status', 'Order duplicated into new cart.');
    }

    public function productSearch(Request $request)
    {
        $q = $request->input('q', '');
        $products = Product::where('is_active', true)
            ->where(function ($qq) use ($q) {
                $qq->where('name', 'like', "%{$q}%")
                   ->orWhere('sku', 'like', "%{$q}%")
                   ->orWhere('barcode', 'like', "%{$q}%");
            })
            ->limit(10)
            ->get(['id', 'name', 'sku', 'stock', 'unit', 'sale_price', 'max_discount_percent']);

        return response()->json($products);
    }
}
