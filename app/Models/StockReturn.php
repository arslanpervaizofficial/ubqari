<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StockReturn extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'type', 'product_id', 'customer_id', 'supplier_id',
        'order_id', 'order_item_id', 'purchase_order_id', 'purchase_order_item_id',
        'quantity', 'unit_price', 'discount_percent', 'discount_amount', 'reason', 'user_id',
    ];

    public function product() { return $this->belongsTo(Product::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function order() { return $this->belongsTo(Order::class); }
    public function orderItem() { return $this->belongsTo(OrderItem::class); }
    public function purchaseOrder() { return $this->belongsTo(PurchaseOrder::class); }
    public function purchaseOrderItem() { return $this->belongsTo(PurchaseOrderItem::class); }

    /** What the customer/supplier actually received back in money terms —
     *  the discount that applied on the original sale (or was entered
     *  manually for a return with no linked order) is subtracted here, so
     *  refunds and reports never use the pre-discount unit_price on its own. */
    public function getNetAmountAttribute(): float
    {
        return round(($this->quantity * $this->unit_price) - $this->discount_amount, 2);
    }

    /** Undoes the stock effect this return had when it was recorded — a
     *  "from_customer" return had added stock (so take it back out), a
     *  "to_supplier" return had removed stock (so put it back). Mirrors
     *  Order::reverseCompletedEffects() / PurchaseOrder::reverseReceivedEffects()
     *  so deleting a stock return behaves the same way: the record moves to
     *  Trash AND the stock effect it had goes with it.
     *
     *  H5 — a customer credit-note payment created alongside a
     *  "from_customer" return is reversed here too: the payment row is
     *  linked to this return via customer_payments.stock_return_id (set
     *  in StockReturnController::customerStore), so reversing finds it,
     *  puts the amount back on the customer's credit_balance, and removes
     *  the payment row. Returns recorded before that link existed have no
     *  linked payment, so nothing is reversed for them — their credit
     *  entries stay as historical ledger entries.
     *
     *  M9 — PO-style guard: the decrementing direction refuses instead of
     *  driving stock negative (a from_customer reverse decrements stock;
     *  a to_supplier restore decrements it — see restoreEffects()).
     *  Caller wraps this in a DB transaction. */
    public function reverseEffects(): void
    {
        // v3.1.0 (C2): own transaction + return row locked (withTrashed
        // so the restore path's inverse locks too). The legs below are
        // SPLIT (v3.1.0 L11d): the old code returned early when the
        // product was missing, silently skipping the customer-credit
        // reversal. Now each leg runs on what it actually needs —
        // stock leg needs the product, PO leg needs the PO line, credit
        // leg needs neither — and only the leg that needs a missing
        // record is skipped (logged). v3.1.0 (H7): stock moves are
        // mirrored in batches. v3.1.0 (H11): a linked customer return
        // also puts its net amount back onto the source order's
        // due_amount (the forward return reduced it).
        DB::transaction(function () {
            $locked = static::withTrashed()->lockForUpdate()->find($this->id);
            if (!$locked) {
                return;
            }

            // Stock leg (needs the product; guard reads a locked row).
            $product = $locked->product_id
                ? Product::lockForUpdate()->find($locked->product_id)
                : null;
            if ($product) {
                if ($locked->type === 'from_customer') {
                    if ((float) $product->stock < (float) $locked->quantity) {
                        throw new \RuntimeException(
                            "Can't delete this return: \"{$product->name}\" only has {$product->stock} in stock, "
                            . "but this return added {$locked->quantity} — some of it has already been sold. "
                            . 'Reduce stock consumption before deleting this return.'
                        );
                    }
                    $product->decrement('stock', $locked->quantity);
                    ProductBatch::mirrorStockDelta((int) $locked->product_id, -(float) $locked->quantity, ['record' => "Stock return #{$locked->id}"]);
                } else {
                    $product->increment('stock', $locked->quantity);
                    ProductBatch::mirrorStockDelta((int) $locked->product_id, (float) $locked->quantity, ['record' => "Stock return #{$locked->id}"]);
                }
            } else {
                Log::warning('Stock return reversal skipped stock leg: product missing (force-deleted). Credit/PO legs still run.', [
                    'stock_return_id' => $locked->id, 'product_id' => $locked->product_id, 'quantity' => $locked->quantity,
                ]);
            }

            // v2.2 (R2): a supplier return reduced the PO line's
            // received_quantity at return time (H8 net invariant), so
            // deleting the return must put it back and recompute the PO
            // totals — otherwise the line stays reduced forever and the
            // same units can be "returned" a second time. Runs in the
            // caller's transaction, same as the stock move above.
            if ($locked->type === 'to_supplier') {
                $locked->adjustPoHeldQuantity((float) $locked->quantity);
            }

            StockMovement::where('stock_return_id', $locked->id)->delete();

            // H5 — reverse the refund credit this return created, if any.
            // The note is soft-deleted (not force-deleted) so that
            // restoreEffects() can put exactly this note back if the return
            // is ever restored, and so a return recorded before the
            // stock_return_id link existed is untouched: it has no linked
            // note, so nothing is reversed for it here.
            // This leg does NOT need the product, so it runs even when
            // the stock leg above was skipped (L11d).
            if ($locked->type === 'from_customer') {
                foreach (CustomerPayment::where('stock_return_id', $locked->id)->get() as $payment) {
                    if ($payment->customer) {
                        $payment->customer->increment('credit_balance', $payment->amount);
                    }
                    $payment->delete();
                }

                // H11 — put the returned amount back on the source
                // order's due (the forward return reduced it). Live
                // orders only: a trashed order's due is stale and its
                // own restore re-applies its effects separately.
                // CAVEAT (pre-fix returns): returns recorded before
                // v3.1.0 never reduced the order due, so reversing one
                // of those now overstates its due by the net amount.
                // That set shrinks with time and the figure stays
                // visible (and correctable) on the order ledger; new
                // returns are symmetric by construction.
                if ($locked->order_id) {
                    $order = Order::lockForUpdate()->find($locked->order_id);
                    if ($order) {
                        $order->increment('due_amount', $locked->net_amount);
                    } else {
                        Log::warning('Stock return reversal skipped order-due leg: source order not live.', [
                            'stock_return_id' => $locked->id, 'order_id' => $locked->order_id,
                        ]);
                    }
                }
            }
        });
    }

    /** The exact inverse of reverseEffects() — used when a stock return is
     *  Restored from Trash, to put back the stock effect that was reversed
     *  when it was deleted. Must run BEFORE $stockReturn->restore(), same
     *  ordering reason as Order::restoreCompletedEffects().
     *
     *  M9 — the decrementing direction (a to_supplier restore) refuses
     *  instead of driving stock negative. H5 mirror — a from_customer
     *  restore puts back exactly the credit note that reverseEffects()
     *  soft-deleted (only if it is still trashed — if it was already
     *  restored on its own through Trash, its own restoreEffect has
     *  already re-applied the balance, so it is skipped here), so
     *  delete/restore stay exact inverses without ever double-applying. */
    public function restoreEffects(): void
    {
        // v3.1.0 (C2/L11d): same locked, split-leg shape as
        // reverseEffects() — own transaction, return row locked
        // (withTrashed: restore runs while the row is still trashed).
        // A missing product skips only the stock leg and the movement
        // row (which needs a live product_id FK); the PO and credit
        // legs still run. v3.1.0 (H7): stock moves mirrored in
        // batches. v3.1.0 (H11): a linked customer return reduces the
        // source order's due again (inverse of the reverse leg).
        DB::transaction(function () {
            $locked = static::withTrashed()->lockForUpdate()->find($this->id);
            if (!$locked) {
                return;
            }

            // v2.2 (R2): guard the PO side BEFORE anything is mutated — if
            // the PO line was corrected downward after this return was
            // recorded, taking the return back would drive the net held
            // figure negative. The caller wraps this in a transaction, so
            // throwing here still rolls everything back.
            if ($locked->type === 'to_supplier') {
                $poItem = $locked->purchaseOrderItem;
                if ($poItem && (float) $poItem->received_quantity < (float) $locked->quantity) {
                    throw new \RuntimeException(
                        "Can't restore this return: the PO line for \"{$poItem->product?->name}\" only holds {$poItem->received_quantity} now, "
                        . "but this return is for {$locked->quantity} — the line was corrected after the return. "
                        . 'Fix the PO line first, then restore this return.'
                    );
                }
            }

            $product = $locked->product_id
                ? Product::lockForUpdate()->find($locked->product_id)
                : null;

            $movementQty = null;
            $reason = null;
            if ($product) {
                if ($locked->type === 'from_customer') {
                    $product->increment('stock', $locked->quantity);
                    ProductBatch::mirrorStockDelta((int) $locked->product_id, (float) $locked->quantity, ['record' => "Stock return #{$locked->id}"]);
                    $reason = 'Customer return restored from Trash';
                    $movementQty = $locked->quantity;
                } else {
                    if ((float) $product->stock < (float) $locked->quantity) {
                        throw new \RuntimeException(
                            "Can't restore this return: \"{$product->name}\" only has {$product->stock} in stock, "
                            . "but restoring this supplier return would remove {$locked->quantity} — some of it has already been sold. "
                            . 'Increase stock before restoring this return.'
                        );
                    }
                    $product->decrement('stock', $locked->quantity);
                    ProductBatch::mirrorStockDelta((int) $locked->product_id, -(float) $locked->quantity, ['record' => "Stock return #{$locked->id}"]);
                    $reason = 'Supplier return restored from Trash';
                    $movementQty = -$locked->quantity;
                }
            } else {
                Log::warning('Stock return restore skipped stock leg: product missing (force-deleted). Credit/PO legs still run.', [
                    'stock_return_id' => $locked->id, 'product_id' => $locked->product_id, 'quantity' => $locked->quantity,
                ]);
            }

            if ($movementQty !== null) {
                StockMovement::create([
                    'product_id' => $locked->product_id,
                    'type' => 'adjustment',
                    'quantity' => $movementQty,
                    'reason' => $reason,
                    'user_id' => auth()->id(),
                    'stock_return_id' => $locked->id,
                ]);
            }

            // v2.2 (R2): exact inverse of the reverseEffects() addition
            // above — restoring a supplier return takes the units back out
            // of the PO line's net held quantity and recomputes PO totals.
            if ($locked->type === 'to_supplier') {
                $locked->adjustPoHeldQuantity(-(float) $locked->quantity);
            }

            if ($locked->type === 'from_customer') {
                foreach (CustomerPayment::withTrashed()->where('stock_return_id', $locked->id)->get() as $payment) {
                    if (!$payment->trashed()) {
                        continue; // already restored on its own — balance already re-applied
                    }
                    $payment->restore();
                    if ($payment->customer) {
                        $payment->customer->decrement('credit_balance', $payment->amount);
                    }
                }

                // H11 — inverse of the reverse leg: the restored return
                // counts against the source order's due again.
                if ($locked->order_id) {
                    $order = Order::lockForUpdate()->find($locked->order_id);
                    if ($order) {
                        $order->decrement('due_amount', $locked->net_amount);
                    } else {
                        Log::warning('Stock return restore skipped order-due leg: source order not live.', [
                            'stock_return_id' => $locked->id, 'order_id' => $locked->order_id,
                        ]);
                    }
                }
            }
        });
    }

    /** v2.2 (R2): the PO side of a supplier return's reversal. A
     *  positive delta adds units back to the PO line's net held
     *  quantity (return deleted), a negative delta takes them away
     *  again (return restored). Recomputes the PO totals with the same
     *  formula StockReturnController::recalculatePoTotals() uses, and
     *  re-syncs the supplier's balance so the ledger follows the PO
     *  total. The PO is loaded withTrashed so a return deleted while
     *  its PO sits in Trash still keeps that PO's figures correct for
     *  when it is restored. Silently skips when the PO line or PO no
     *  longer exists (nothing left to adjust). Requires the v2.2
     *  Supplier model (syncBalance) — install the whole package. */
    private function adjustPoHeldQuantity(float $delta): void
    {
        $poItem = $this->purchaseOrderItem;
        if (!$poItem) {
            return;
        }
        $po = PurchaseOrder::withTrashed()->find($poItem->purchase_order_id);
        if (!$po) {
            return;
        }
        $poItem->increment('received_quantity', $delta);
        $this->recalculatePoTotals($po);
        $supplier = Supplier::withTrashed()->find($po->supplier_id);
        if ($supplier) {
            $supplier->syncBalance();
        }
    }

    /** Same formula as StockReturnController::recalculatePoTotals()
     *  (duplicated here because that method is private to the
     *  controller and this model needs it on the Trash paths). */
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
}
