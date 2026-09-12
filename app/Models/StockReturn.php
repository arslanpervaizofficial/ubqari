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
     *  Trash AND the stock effect it had goes with it. Only the stock/movement
     *  effect is reversed here — a customer credit-note payment created
     *  alongside a "from_customer" return is left as its own historical
     *  ledger entry, the same way deleting a Purchase Order never touches
     *  a supplier ledger. Caller wraps this in a DB transaction. */
    public function reverseEffects(): void
    {
        if (!$this->product) {
            return;
        }

        if ($this->type === 'from_customer') {
            $this->product->decrement('stock', $this->quantity);
        } else {
            $this->product->increment('stock', $this->quantity);
        }

        StockMovement::where('stock_return_id', $this->id)->delete();
    }

    /** The exact inverse of reverseEffects() — used when a stock return is
     *  Restored from Trash, to put back the stock effect that was reversed
     *  when it was deleted. Must run BEFORE $stockReturn->restore(), same
     *  ordering reason as Order::restoreCompletedEffects(). */
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
    }
}
