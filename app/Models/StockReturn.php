<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        if (!$this->product) {
            return;
        }

        if ($this->type === 'from_customer') {
            if ($this->product->stock < $this->quantity) {
                throw new \RuntimeException(
                    "Can't delete this return: \"{$this->product->name}\" only has {$this->product->stock} in stock, "
                    . "but this return added {$this->quantity} — some of it has already been sold. "
                    . 'Reduce stock consumption before deleting this return.'
                );
            }
            $this->product->decrement('stock', $this->quantity);
        } else {
            $this->product->increment('stock', $this->quantity);
        }

        StockMovement::where('stock_return_id', $this->id)->delete();

        // H5 — reverse the refund credit this return created, if any.
        // The note is soft-deleted (not force-deleted) so that
        // restoreEffects() can put exactly this note back if the return
        // is ever restored, and so a return recorded before the
        // stock_return_id link existed is untouched: it has no linked
        // note, so nothing is reversed for it here.
        if ($this->type === 'from_customer') {
            foreach (CustomerPayment::where('stock_return_id', $this->id)->get() as $payment) {
                if ($payment->customer) {
                    $payment->customer->increment('credit_balance', $payment->amount);
                }
                $payment->delete();
            }
        }
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
        if (!$this->product) {
            return;
        }

        if ($this->type === 'from_customer') {
            $this->product->increment('stock', $this->quantity);
            $reason = 'Customer return restored from Trash';
            $movementQty = $this->quantity;
        } else {
            if ($this->product->stock < $this->quantity) {
                throw new \RuntimeException(
                    "Can't restore this return: \"{$this->product->name}\" only has {$this->product->stock} in stock, "
                    . "but restoring this supplier return would remove {$this->quantity} — some of it has already been sold. "
                    . 'Increase stock before restoring this return.'
                );
            }
            $this->product->decrement('stock', $this->quantity);
            $reason = 'Supplier return restored from Trash';
            $movementQty = -$this->quantity;
        }

        StockMovement::create([
            'product_id' => $this->product_id,
            'type' => 'adjustment',
            'quantity' => $movementQty,
            'reason' => $reason,
            'user_id' => auth()->id(),
            'stock_return_id' => $this->id,
        ]);

        if ($this->type === 'from_customer') {
            foreach (CustomerPayment::withTrashed()->where('stock_return_id', $this->id)->get() as $payment) {
                if (!$payment->trashed()) {
                    continue; // already restored on its own — balance already re-applied
                }
                $payment->restore();
                if ($payment->customer) {
                    $payment->customer->decrement('credit_balance', $payment->amount);
                }
            }
        }
    }
}
