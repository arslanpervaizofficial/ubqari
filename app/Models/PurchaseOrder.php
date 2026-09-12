<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PurchaseOrder extends Model
{
    use SoftDeletes;

    protected $fillable = ['supplier_id', 'status', 'subtotal', 'discount_percent', 'discount_amount', 'total'];

    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function items() { return $this->hasMany(PurchaseOrderItem::class); }

    /** Undoes the stock this PO added when it was received — takes back out
     *  exactly the received quantity per line, and removes the "purchase"
     *  stock-movement log lines it created. Mirrors
     *  Order::reverseCompletedEffects() so deleting a PO behaves the same
     *  way deleting a Sales Order does: the record moves to Trash AND the
     *  stock/history effect it had goes with it, rather than leaving stock
     *  permanently inflated by a purchase nobody can see a record of any
     *  more. Caller wraps this in a DB transaction. */
    public function reverseReceivedEffects(): void
    {
        if ($this->status !== 'received') {
            return;
        }

        foreach ($this->items()->with('product')->get() as $item) {
            if ($item->received_quantity > 0) {
                $item->product->decrement('stock', $item->received_quantity);
            }
        }

        StockMovement::where('purchase_order_id', $this->id)->where('type', 'purchase')->delete();
    }

    /** The exact inverse of reverseReceivedEffects() — used when a PO is
     *  Restored from Trash, to put back the stock it added when it was
     *  received. Must run BEFORE $purchaseOrder->restore(), same ordering
     *  reason as Order::restoreCompletedEffects(). */
    public function restoreReceivedEffects(): void
    {
        if ($this->status !== 'received') {
            return;
        }

        foreach ($this->items()->with('product')->get() as $item) {
            if ($item->received_quantity > 0) {
                $item->product->increment('stock', $item->received_quantity);

                StockMovement::create([
                    'product_id' => $item->product_id,
                    'type' => 'purchase',
                    'quantity' => $item->received_quantity,
                    'reason' => "PO #{$this->id} restored from Trash",
                    'user_id' => auth()->id(),
                    'purchase_order_id' => $this->id,
                ]);
            }
        }
    }
}
