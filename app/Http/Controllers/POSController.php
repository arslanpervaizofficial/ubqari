<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class POSController extends Controller
{
    /** Admins and managers may act on another cashier's order; cashiers may
     *  only act on their own. Used by the ownership guards below (C3/C4). */
    private function isPrivileged(): bool
    {
        $user = auth()->user();
        return $user && ($user->isAdmin() || $user->isManager());
    }

    /** Ownership guard for POS order actions (C4): abort 403 unless the
     *  order belongs to the caller or the caller is admin/manager. When
     *  $requireSession is true, a non-privileged caller must also have
     *  this order as their active cart in the session. */
    private function assertPosOrderAccess(Order $order, Request $request, bool $requireSession = false): void
    {
        if (!$this->isPrivileged() && (int) $order->user_id !== (int) auth()->id()) {
            abort(403, 'You can only work on your own orders.');
        }
        if ($requireSession && !$this->isPrivileged()
            && (int) $request->session()->get('current_order_id') !== (int) $order->id) {
            abort(403, 'This order is not your active cart.');
        }
    }

    /** Guard for the cart-item JSON endpoints (C3): the parent order must
     *  be the caller's active in_progress cart (admin/manager excepted).
     *  Returns null on success, or a JSON error response to return. */
    private function cartItemGuard(OrderItem $item, Request $request)
    {
        $order = $item->order;
        if (!$order) {
            return response()->json(['error' => 'Order not found.'], 404);
        }
        if ($order->status !== 'in_progress') {
            return response()->json(['error' => 'Only the active cart can be edited. Completed orders must go through "Load Order" / Edit first.'], 403);
        }
        if (!$this->isPrivileged()
            && (int) $request->session()->get('current_order_id') !== (int) $order->id) {
            return response()->json(['error' => 'This cart belongs to another session.'], 403);
        }
        return null;
    }

    public function index(Request $request)
    {
        $order = $this->getOrCreateCurrentOrder($request);
        $order->load('items.product', 'customer');
        $this->withRecomputedBalance($order);

        $heldOrders = Order::where('status', 'hold')
            ->where('user_id', auth()->id())
            ->with('customer')
            ->withCount('items')
            ->latest()
            ->get();

        $customers = Customer::where('is_active', true)->orderBy('name')->get();

        return view('pos.index', compact('order', 'heldOrders', 'customers'));
    }

    /** v3.2.5: attach the recomputed ledger balance to the order's
     *  customer as a runtime attribute (never saved), so the billing
     *  page, its JSON responses and the invoice show ledgerFigures(),
     *  not the stored credit_balance column. The blade/JS read
     *  recomputed_balance ?? credit_balance, so any render path that
     *  forgets to attach it falls back to the stored value. */
    private function withRecomputedBalance(Order $order): Order
    {
        if ($order->customer) {
            $order->customer->recomputed_balance = $order->customer->recomputedBalance();
        }
        return $order;
    }

    /** "Previous order id" field on the billing screen — loads a completed
     *  order back into the cart so it can be corrected and re-completed.
     *  Reverses its stock/ledger effects first (same as OrderController::edit)
     *  so re-completing it recounts stock and payment correctly instead of
     *  double-counting. */
    public function loadOrderForEdit(Request $request)
    {
        $data = $request->validate(['order_number' => ['required', 'string']]);

        // FIXED (H1): the old ungrouped orWhere produced
        // "order_number = ? OR (id = ? AND status = completed)" — the status
        // filter did not apply to the order-number branch, so a hold or
        // in_progress order could be loaded by its number. Grouping the
        // OR inside a closure applies status=completed to BOTH branches.
        $order = Order::where(function ($q) use ($data) {
                $q->where('order_number', trim($data['order_number']))
                    ->orWhere('id', is_numeric($data['order_number']) ? (int) $data['order_number'] : 0);
            })
            ->where('status', 'completed')
            ->first();

        if (!$order) {
            return back()->withErrors(['error' => 'No completed order found with that order number/ID.']);
        }

        DB::transaction(function () use ($order, $request) {
            $currentId = $request->session()->get('current_order_id');
            if ($currentId && $currentId != $order->id) {
                $current = Order::where('id', $currentId)->when(!$this->isPrivileged(), fn ($q) => $q->where('user_id', auth()->id()))->where('status', 'in_progress')->first();
                if ($current) {
                    $current->items()->count() > 0 ? $current->forceFill(['status' => 'hold'])->save() : $current->forceDelete();
                }
            }

            $order->reverseCompletedEffects();
            $order->forceFill(['status' => 'in_progress'])->save();
        });

        $request->session()->put('current_order_id', $order->id);

        return redirect()->route('pos.index')->with('status', "Order {$order->order_number} loaded — edit and click Update Order.");
    }

    private function getOrCreateCurrentOrder(Request $request): Order
    {
        $orderId = $request->session()->get('current_order_id');

        if ($orderId) {
            // v2.2 (F3): a cashier's session cart belongs to its owner
            // only — a session value pointing at another cashier's open
            // cart must not be honoured as the current cart. Admins and
            // managers are excepted: after "Load Order for Edit" their
            // session legitimately points at another user's order.
            $order = Order::where('id', $orderId)->when(!$this->isPrivileged(), fn ($q) => $q->where('user_id', auth()->id()))->where('status', 'in_progress')->first();
            if ($order) {
                return $order;
            }
        }

        // Safety net: this cashier had an in_progress order tracked by a
        // previous session (e.g. after auto-logout). Only preserve it as
        // "held" if it actually has items — an empty cart should never
        // clutter the Held Orders list. Orders normally only become Held
        // when the cashier explicitly clicks "+ New Order" or "Save as Draft".
        // v2.2 (F3): only carts untouched for > 15 minutes are stray-held
        // here — a cart touched in the last few minutes may be live on
        // another device, and holding it would make its items vanish
        // mid-transaction on that device.
        Order::where('user_id', auth()->id())
            ->where('status', 'in_progress')
            ->where('updated_at', '<', now()->subMinutes(15))
            ->get()
            ->each(function ($stray) {
                if ($stray->items()->count() > 0) {
                    $stray->forceFill(['status' => 'hold'])->save();
                } else {
                    // Empty cart: permanently discard, never send to Trash (v1.4).
                    $stray->forceDelete();
                }
            });

        // v3.1.0 (L4): status is no longer in Order::$fillable.
        $order = new Order;
        $order->forceFill([
            'order_number' => 'ORD-' . now()->format('Ymd') . '-' . strtoupper(Str::random(5)),
            'user_id' => auth()->id(),
            'status' => 'in_progress',
        ])->save();

        $request->session()->put('current_order_id', $order->id);

        return $order;
    }

    public function newOrder(Request $request)
    {
        $currentId = $request->session()->get('current_order_id');
        if ($currentId) {
            $current = Order::where('id', $currentId)->when(!$this->isPrivileged(), fn ($q) => $q->where('user_id', auth()->id()))->where('status', 'in_progress')->first();
            if ($current) {
                if ($current->items()->count() > 0) {
                    $current->forceFill(['status' => 'hold'])->save();
                } else {
                    // Empty cart: permanently discard, never send to Trash (v1.4).
                    $current->forceDelete();
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

    public function showHeld(Request $request, Order $order)
    {
        if ($order->status !== 'hold') {
            abort(404);
        }
        // FIXED (C4): list filtering alone is not access control — a
        // cashier could previously open/resume any other cashier's held
        // order by guessing its sequential id.
        $this->assertPosOrderAccess($order, $request);
        $order->load('items.product', 'customer');
        return view('pos.held_detail', compact('order'));
    }

    public function resume(Request $request, Order $order)
    {
        if ($order->status !== 'hold') {
            return back()->withErrors(['error' => 'Only held orders can be resumed.']);
        }
        $this->assertPosOrderAccess($order, $request);

        $currentId = $request->session()->get('current_order_id');
        if ($currentId && $currentId != $order->id) {
            $current = Order::where('id', $currentId)->when(!$this->isPrivileged(), fn ($q) => $q->where('user_id', auth()->id()))->where('status', 'in_progress')->first();
            if ($current) {
                if ($current->items()->count() > 0) {
                    $current->forceFill(['status' => 'hold'])->save();
                } else {
                    // Empty cart: permanently discard, never send to Trash (v1.4).
                    $current->forceDelete();
                }
            }
        }

        $order->forceFill(['status' => 'in_progress'])->save();
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

        // FIXED (M4): the search UI hides inactive products, but addItem
        // previously accepted any existing product id by direct POST, so a
        // deactivated product could still be sold.
        if (!$product->is_active) {
            return response()->json(['error' => "{$product->name} is inactive and cannot be sold."], 422);
        }

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
            'order' => $this->withRecomputedBalance($order->fresh('items.product', 'customer')),
            'available_stock' => $this->availableStock($product),
            'warning' => $warning,
        ]);
    }

    public function removeItem(Request $request, OrderItem $item)
    {
        if ($guard = $this->cartItemGuard($item, $request)) {
            return $guard;
        }
        $order = $item->order;
        $item->delete();
        $this->recalculateTotals($order);

        return response()->json(['success' => true, 'order' => $this->withRecomputedBalance($order->fresh('items.product', 'customer'))]);
    }

    public function updateItemQuantity(Request $request, OrderItem $item)
    {
        if ($guard = $this->cartItemGuard($item, $request)) {
            return $guard;
        }
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
            'order' => $this->withRecomputedBalance($item->order->fresh('items.product', 'customer')),
            'warning' => $warning,
        ]);
    }

    /** Per-item discount. FIXED (M5): above the product's max_discount_percent
     *  used to be ALLOWED for everyone with only a warning ("Applied anyway"),
     *  so the max was advisory, not a control. Now a cashier is REJECTED
     *  (422, nothing saved); only admin/manager may override, and the
     *  warning is still returned for them so the override stays visible. */
    public function updateItemDiscount(Request $request, OrderItem $item)
    {
        if ($guard = $this->cartItemGuard($item, $request)) {
            return $guard;
        }
        $data = $request->validate(['discount_percent' => ['required', 'numeric', 'min:0', 'max:100']]);

        $maxDiscount = $item->product ? (float) $item->product->max_discount_percent : 0;
        if ($maxDiscount > 0 && $data['discount_percent'] > $maxDiscount && auth()->user()->isCashier()) {
            return response()->json(['error' => "Discount {$data['discount_percent']}% exceeds the max allowed for {$item->product->name} ({$maxDiscount}%). Ask a manager to approve it."], 422);
        }

        $item->discount_percent = $data['discount_percent'];
        $this->recalculateLineTotal($item);
        $item->save();

        $this->recalculateTotals($item->order);

        $exceedsMax = $item->product->max_discount_percent > 0
            && $data['discount_percent'] > $item->product->max_discount_percent;

        return response()->json([
            'success' => true,
            'order' => $this->withRecomputedBalance($item->order->fresh('items.product', 'customer')),
            'warning' => $exceedsMax
                ? "{$item->product->name}: discount {$data['discount_percent']}% exceeds max allowed ({$item->product->max_discount_percent}%). Applied anyway — adjust if needed."
                : null,
        ]);
    }

    /** Overall order-level discount, editable independently of the customer's default.
     *  FIXED (M5): previously any cashier could set it to 100% with no cap
     *  at all. Now a cashier is capped at the current customer's standing
     *  default discount (0% for walk-in); admin/manager are unrestricted. */
    public function setOverallDiscount(Request $request)
    {
        $order = $this->getOrCreateCurrentOrder($request);
        $data = $request->validate(['discount_percent' => ['required', 'numeric', 'min:0', 'max:100']]);

        if (auth()->user()->isCashier()) {
            $cap = $order->customer_id ? (float) (Customer::find($order->customer_id)->discount_percent ?? 0) : 0;
            if ($data['discount_percent'] > $cap) {
                return response()->json(['error' => "Cashiers can only set an overall discount up to the customer's default ({$cap}%). Ask a manager for more."], 422);
            }
        }

        $order->discount_percent = $data['discount_percent'];
        $order->save();
        $this->recalculateTotals($order);

        return response()->json(['success' => true, 'order' => $this->withRecomputedBalance($order->fresh('items.product', 'customer'))]);
    }

    public function setCustomer(Request $request)
    {
        $order = $this->getOrCreateCurrentOrder($request);
        $data = $request->validate(['customer_id' => ['nullable', 'exists:customers,id']]);

        // FIXED (M2): the customer's default discount used to OVERWRITE
        // whatever overall discount was already on the order, every time
        // the party was selected or re-selected — a cashier-entered 10%
        // silently became the customer's 5% (or 0%). Now the default is
        // applied only when no discount is set yet, or when the current
        // value is just the previous customer's default (i.e. not a
        // cashier-entered value). A cashier-entered discount is never
        // overwritten; switching to walk-in only drops a previous
        // customer's default, never a cashier-entered value. (Discounts
        // are stored as a single number, so "cashier-entered" is inferred
        // as "differs from the previous customer's default" — that is the
        // only distinction available without a new column.)
        $oldCustomerId = $order->customer_id;
        $oldDefault = $oldCustomerId ? (float) (Customer::find($oldCustomerId)->discount_percent ?? 0) : null;
        $currentDiscount = (float) $order->discount_percent;
        $order->customer_id = $data['customer_id'] ?? null;
        if ($order->customer_id) {
            $newDefault = (float) (Customer::find($order->customer_id)->discount_percent ?? 0);
            if ($currentDiscount == 0 || ($oldDefault !== null && $currentDiscount == $oldDefault)) {
                $order->discount_percent = $newDefault;
            }
        } else {
            if ($oldDefault !== null && $currentDiscount == $oldDefault) {
                $order->discount_percent = 0;
            }
        }
        $order->save();
        $this->recalculateTotals($order);

        return response()->json(['success' => true, 'order' => $this->withRecomputedBalance($order->fresh('items.product', 'customer'))]);
    }

    private function availableStock(Product $product): float
    {
        // v2.2 (F1): quotations are estimates, not sales — they must not
        // reserve stock (and, having status hold, they were never purged,
        // so a forgotten estimate blocked the goods forever).
        $reserved = OrderItem::where('product_id', $product->id)
            ->whereHas('order', fn ($q) => $q->whereIn('status', ['in_progress', 'hold'])->where('is_quotation', false))
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

        // v3.1.0 (M13): round subtotal AND total to 2dp BEFORE
        // persisting — the old code stored the unrounded float sum, so
        // the stored total could differ by a cent from
        // subtotal-minus-discounts, and the stored due from
        // total-minus-paid. Now: stored total == stored subtotal
        // minus stored discounts, and stored due == stored total
        // minus stored paid (signed for named customers, clamped at
        // 0 for walk-in — see M1 note below).
        $subtotal = round($grossSubtotal, 2);
        $lineDiscountTotal = round($lineDiscountTotal, 2);
        $afterLineDiscounts = $subtotal - $lineDiscountTotal;

        $overallDiscountAmount = round($afterLineDiscounts * ($order->discount_percent / 100), 2);
        $total = round($afterLineDiscounts - $overallDiscountAmount, 2);

        // FIXED (M1): due_amount used to be clamped with max(0, ...) for
        // everyone, which silently erased a named customer's signed
        // advance (overpayment) the first time any line was edited after
        // loading a completed order for edit. For named customers the due
        // stays SIGNED (total - paid; negative = advance), exactly as
        // complete() already computes it. Walk-in has no account to hold
        // an advance on, so it still clamps at 0 (the excess is change).
        // v3.1.0 (L4): total/due_amount are no longer fillable.
        $order->forceFill([
            'subtotal' => $subtotal,
            'line_discount_total' => $lineDiscountTotal,
            'discount_amount' => $overallDiscountAmount,
            'total' => $total,
            'due_amount' => $order->customer_id
                ? round($total - $order->paid_amount, 2)
                : max(0, round($total - $order->paid_amount, 2)),
        ])->save();
    }

    public function complete(Request $request, Order $order)
    {
        // FIXED (C4): only the order's owner, or admin/manager, may
        // complete it — and a non-privileged caller must have it as their
        // active session cart, so completing it cannot strand the original
        // cashier's session on a now-completed order.
        $this->assertPosOrderAccess($order, $request, true);

        // FIXED (C1): previously the ONLY guard here was the empty-cart
        // check — a completed order could be completed again (double-click,
        // re-POST, back-button resubmit), decrementing stock, creating a
        // second set of sale movements and incrementing customer credit a
        // second time. Now only in_progress or hold orders complete.
        if (!in_array($order->status, ['in_progress', 'hold'])) {
            return back()->withErrors(['error' => 'Only an active or held order can be completed. This order is already completed.']);
        }

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

        // v2.2 (R1) — see the unified checkout computation inside the
        // transaction below: checkout input is authoritative again, and
        // when ledger payments exist the difference is written as one
        // OrderPayment row instead of silently dropping the till payment.
        $hasPayments = $order->payments()->exists();

        // v3.1.0 (M17) — WARN, do not block: expired batches sold in this
        // order are collected here and flashed separately below. The check
        // itself must never throw, so it is wrapped in try/catch.
        $expiredSaleWarnings = [];
        DB::transaction(function () use ($order, $data, $hasPayments, &$expiredSaleWarnings) {
            // Lock the order row first and re-check status inside the
            // transaction (C1): without this, two concurrent completes
            // could both pass the pre-transaction status check.
            $locked = Order::where('id', $order->id)->lockForUpdate()->firstOrFail();
            if (!in_array($locked->status, ['in_progress', 'hold'])) {
                throw ValidationException::withMessages(['error' => 'This order has already been completed.']);
            }

            $items = $locked->items()->with('product')->get();

            // FIXED (H2): lock every product row and verify stock BEFORE
            // decrementing anything. Previously complete() never re-checked
            // availability, so two cashiers selling the last units, or a
            // stock change between add and complete, drove stock negative.
            // Throwing aborts the whole transaction — no partial sale.
            $products = [];
            foreach ($items as $item) {
                $product = Product::where('id', $item->product_id)->lockForUpdate()->firstOrFail();
                if ((float) $product->stock < (float) $item->quantity) {
                    throw ValidationException::withMessages(['error' => "Not enough stock for {$product->name}: {$product->stock} available, {$item->quantity} needed."]);
                }
                $products[$item->product_id] = $product;
            }

            // v3.1.0 (M17): which of the batches about to be consumed are
            // already expired? Warning only — expired stock is still sold.
            try {
                if (Schema::hasTable('product_batches')) {
                    foreach ($items as $warnItem) {
                        $expired = ProductBatch::where('product_id', $warnItem->product_id)
                            ->where('remaining_quantity', '>', 0)
                            ->whereNotNull('expiry_date')
                            ->whereDate('expiry_date', '<', now()->toDateString())
                            ->orderBy('expiry_date')->get();
                        foreach ($expired as $batch) {
                            $expiredSaleWarnings[] = $products[$warnItem->product_id]->name . ' (batch ' . $batch->batch_no . ')';
                        }
                    }
                }
            } catch (\Throwable $e) {
                report($e);
            }

            foreach ($items as $item) {
                $products[$item->product_id]->decrement('stock', $item->quantity);
                // v3.0 FEATURE 1 — also decrement batches FIFO
                // (earliest expiry first). product.stock above stays
                // the authority: if batched remaining is short (old
                // unbatched stock), only what batched stock exists is
                // decremented and the rest is silently unbatched.
                // consumeFifo() never throws, so a batch problem can
                // never block or fail this sale. v3.1.0 (H8): the
                // order context is passed so a non-missing-table
                // failure is logged with it and surfaced below.
                ProductBatch::consumeFifo((int) $item->product_id, (float) $item->quantity, ['record' => "Order #{$locked->id}"]);
                StockMovement::create([
                    'product_id' => $item->product_id,
                    'type' => 'sale',
                    'quantity' => -$item->quantity,
                    'user_id' => auth()->id(),
                    'order_id' => $locked->id,
                ]);
            }

            // v2.2 (R1): checkout input is authoritative again — the
            // ORIGINAL semantics. v2.0 preserved the stored figures
            // whenever OrderPayment rows existed, which silently threw
            // away money actually taken at the till: an advance of 4,000
            // on file + 6,000 collected at the till on a 10,000 bill
            // stayed "paid 4,000, due 6,000", and the customer was shown
            // as owing 6,000 they had already paid (feeding Capital
            // Receivables). The ledger history is NOT wiped by this:
            // when ledger payments exist and the checkout total differs
            // from the stored paid figure, one OrderPayment row for the
            // difference is written below, so the ledger still
            // reconciles to the order. NOTE: the checkout paid field is
            // the TOTAL paid, not an additional amount — same as the
            // original code, and the checkout form defaults it to the
            // order total for an in_progress order.
            $paid = $data['payment_method'] === 'split'
                ? (float) ($data['cash_amount'] ?? 0) + (float) ($data['bank_amount'] ?? 0)
                : (float) ($data['paid_amount'] ?? $locked->total);

            // Named customer: due is SIGNED — if they hand over more than the
            // bill (e.g. 25,000 against 24,476), the extra (-524) is kept as
            // a negative due/advance on their account instead of being
            // silently thrown away by max(0, ...). Walk-in (no customer)
            // has no account to hold an advance on, so the extra is just
            // change handed back and due stays at 0.
            $due = $locked->customer_id
                ? round($locked->total - $paid, 2)
                : max(0, round($locked->total - $paid, 2));

            $paymentMethod = $data['payment_method'];
            $bankName = $data['bank_name'] ?? null;
            $transactionId = $data['transaction_id'] ?? null;

            // FIXED (H23-part): the split breakdown used to be computed
            // and then thrown away — cash_amount/bank_amount were never
            // persisted, so Cash Reconciliation could never count the
            // cash half of a split sale. Now it is stored on the order
            // (columns created by the migration package): split stores
            // both parts; cash stores cash_amount=paid, bank null; bank
            // stores bank_amount=paid, cash null.
            if ($data['payment_method'] === 'split') {
                $cashAmount = (float) ($data['cash_amount'] ?? 0);
                $bankAmount = (float) ($data['bank_amount'] ?? 0);
            } elseif ($data['payment_method'] === 'cash') {
                $cashAmount = $paid;
                $bankAmount = null;
            } else {
                $cashAmount = null;
                $bankAmount = $paid;
            }

            // v2.2 (R1, ledger side): a positive difference is money
            // taken at the till beyond what the ledger already shows
            // (credit); a negative difference is a downward correction
            // of the recorded paid figure (debit). No row when the
            // checkout total equals the stored paid figure. This row is
            // history only — the order totals above are already set from
            // the checkout input, so nothing here double-adjusts them,
            // and the customer-balance increment at the end of this
            // transaction uses the final $due exactly once.
            if ($hasPayments) {
                $delta = round($paid - (float) $locked->paid_amount, 2);
                if (abs($delta) > 0.004) {
                    $locked->payments()->create([
                        'type' => $delta > 0 ? 'credit' : 'debit',
                        'amount' => abs($delta),
                        'note' => 'Checkout adjustment at completion — ledger brought in line with till payment',
                        'user_id' => auth()->id(),
                    ]);
                }
            }

            // v3.1.0 (L4): status/paid_amount/due_amount are no longer
            // fillable — the whole completion write goes via forceFill().
            $locked->forceFill([
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
                'payment_method' => $paymentMethod,
                'bank_name' => $bankName,
                'transaction_id' => $transactionId,
                'paid_amount' => $paid,
                'due_amount' => $due,
                'cash_amount' => $cashAmount,
                'bank_amount' => $bankAmount,
                // Set once, on the very first completion, and never touched
                // again — even when this same order is later reopened and
                // re-completed through the "load order to edit" flow.
                'original_completed_at' => $locked->original_completed_at ?? now(),
            ])->save();

            if ($locked->customer_id && abs($due) > 0.004) {
                $locked->customer()->increment('credit_balance', $due);
            }
        });

        if ($request->session()->get('current_order_id') == $order->id) {
            $request->session()->forget('current_order_id');
        }

        // v3.1.0 (H8): a batch failure never fails the sale, but it
        // must not vanish silently either. There is no dedicated
        // 'warning' flash slot in the layouts, so the warning is
        // appended to the status message, which is always displayed.
        $batchError = ProductBatch::takeLastBatchError();
        $status = 'Order completed.'
            . ($batchError ? " Warning: {$batchError}" : '');

        $redirect = redirect()->route('pos.invoice', $order)->with('status', $status);
        if ($expiredSaleWarnings !== []) {
            $redirect->with('expiry_warning', 'Warning: expired stock was sold: ' . implode(', ', array_unique($expiredSaleWarnings)) . '. Sales are not blocked on expired stock yet.');
        }

        return $redirect;
    }

    public function saveAsQuotation(Request $request, Order $order)
    {
        // FIXED (C2): previously ANY order — including a completed sale —
        // could be flipped to a held "quotation" with no stock/ledger
        // reversal, so the sale vanished from reports while stock and
        // customer credit still reflected it (and it could then be
        // resumed and completed a second time). Now only in_progress or
        // hold orders can become a quotation.
        if (!in_array($order->status, ['in_progress', 'hold'])) {
            return back()->withErrors(['error' => 'Only an active or held order can be saved as a quotation. A completed order must be edited through "Load Order" / Edit instead.']);
        }
        $this->assertPosOrderAccess($order, $request);
        $order->forceFill(['is_quotation' => true, 'status' => 'hold'])->save();
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
        // FIXED (C2): previously ANY order — including a completed sale —
        // was bare soft-deleted here with no reverseCompletedEffects(), so
        // the sale vanished while stock stayed deducted and customer
        // credit stayed raised (and a later Trash restore re-applied the
        // sale a second time). Cancel is only for held orders, which were
        // never completed and have nothing to reverse. A completed order
        // must be deleted from the Orders list (OrderController::destroy),
        // which reverses stock and balance first.
        if ($order->status !== 'hold') {
            return back()->withErrors(['error' => 'Only a held order can be moved to Trash from here. To delete a completed order, use Delete on the Orders list — that reverses stock and customer balance first.']);
        }
        $this->assertPosOrderAccess($order, $request);
        $order->delete();
        if ($request->session()->get('current_order_id') == $order->id) {
            $request->session()->forget('current_order_id');
        }
        return redirect()->route('pos.held-index')->with('status', 'Held order moved to Trash.');
    }

    public function invoice(Request $request, Order $order)
    {
        // FIXED (C4): any cashier could previously view any order's
        // invoice (including customer details) by id.
        $this->assertPosOrderAccess($order, $request);
        $order->load('items.product', 'customer', 'cashier');
        $this->withRecomputedBalance($order);
        return view('pos.invoice', compact('order'));
    }

    public function reorder(Request $request, Order $order)
    {
        // v3.1.0 (M2): FIRST line — ownership guard, same as invoice()
        // above. Previously any cashier could duplicate ANY order into
        // their cart (with its customer and prices) by id.
        $this->assertPosOrderAccess($order, $request);

        // FIXED (M4): validate every source product BEFORE touching the
        // current cart — an inactive product aborts the whole reorder
        // (422-style error on this redirect flow) instead of partially
        // building a cart. Missing products (force-deleted) are skipped
        // below, since there is nothing to sell.
        foreach ($order->items as $sourceItem) {
            $product = $sourceItem->product;
            if ($product && !$product->is_active) {
                return back()->withErrors(['error' => "Cannot re-order: {$product->name} is inactive."]);
            }
            // v3.1.0 (M2): re-apply the per-item max-discount check from
            // updateItemDiscount() here, BEFORE the cart is touched. A
            // cashier re-ordering an old order whose line discount was
            // set above the product's current max (e.g. by a manager
            // override at the time) is refused instead of silently
            // copying the over-max discount verbatim; admin/manager
            // may still re-order it, exactly as updateItemDiscount()
            // allows their override.
            $maxDiscount = $product ? (float) $product->max_discount_percent : 0;
            if ($maxDiscount > 0 && (float) $sourceItem->discount_percent > $maxDiscount && auth()->user()->isCashier()) {
                return back()->withErrors(['error' => "Cannot re-order: {$product->name} has a discount of {$sourceItem->discount_percent}%, above the max allowed ({$maxDiscount}%). Ask a manager to approve it."]);
            }
        }

        // FIXED (M3): reorder used to APPEND the old order's items to
        // whatever open cart the cashier already had (possibly another
        // customer's, with that cart's overall discount still applied) —
        // "duplicate this order" silently became "merge two customers'
        // carts". Now it always starts from a FRESH cart: the current
        // cart, if it has items, is held first (same rule as + New
        // Order); an empty one is discarded, never sent to Trash (v1.4).
        $currentId = $request->session()->get('current_order_id');
        if ($currentId) {
            $current = Order::where('id', $currentId)->when(!$this->isPrivileged(), fn ($q) => $q->where('user_id', auth()->id()))->where('status', 'in_progress')->first();
            if ($current) {
                if ($current->items()->count() > 0) {
                    $current->forceFill(['status' => 'hold'])->save();
                } else {
                    $current->forceDelete();
                }
            }
        }
        $request->session()->forget('current_order_id');
        $newOrder = $this->getOrCreateCurrentOrder($request);

        foreach ($order->items as $sourceItem) {
            $product = $sourceItem->product;
            if (!$product) continue;
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
        // The reordered cart is priced with THAT customer's default
        // overall discount, not whatever the previous open cart had.
        $newOrder->discount_percent = $order->customer_id
            ? (Customer::find($order->customer_id)->discount_percent ?? 0)
            : 0;
        $newOrder->save();
        $this->recalculateTotals($newOrder);

        return redirect()->route('pos.index')->with('status', 'Order duplicated into new cart.');
    }

    public function productSearch(Request $request)
    {
        $q = $request->input('q', '');
        $columns = ['id', 'name', 'sku', 'barcode', 'stock', 'unit', 'sale_price', 'max_discount_percent'];

        // v2.2 (F4): a scanned barcode is an exact identifier, not a
        // substring. If the query IS a barcode, return only the exact
        // match(es) — never an unordered top-10 where the real product
        // can sit below an unrelated first result that Enter then
        // picks. Otherwise fall back to the substring search, ordered
        // by name so the order is stable.
        $exact = Product::where('is_active', true)
            ->where('barcode', $q)
            ->orderBy('name')
            ->limit(10)
            ->get($columns);

        $products = $exact->isNotEmpty()
            ? $exact
            : Product::where('is_active', true)
                ->where(function ($qq) use ($q) {
                    $qq->where('name', 'like', "%{$q}%")
                       ->orWhere('sku', 'like', "%{$q}%")
                       ->orWhere('barcode', 'like', "%{$q}%");
                })
                ->orderBy('name')
                ->limit(10)
                ->get($columns);

        // The dropdown used to show raw stock, ignoring items reserved
        // in other carts. Attach the reserved-net figure (same helper
        // the cart itself uses) so the till never promises stock that
        // is not actually free.
        $products->each(function ($p) {
            $p->available_stock = $this->availableStock($p);
        });

        return response()->json($products);
    }
}
