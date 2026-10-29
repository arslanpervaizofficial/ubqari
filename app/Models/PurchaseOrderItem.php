<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    protected $fillable = ['purchase_order_id', 'product_id', 'quantity', 'received_quantity', 'cost_price', 'discount_percent'];

    public function purchaseOrder() { return $this->belongsTo(PurchaseOrder::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function returns() { return $this->hasMany(StockReturn::class); }

    /** Net cost per unit after this line's discount is applied. */
    public function getNetCostPriceAttribute(): float
    {
        return round($this->cost_price * (1 - $this->discount_percent / 100), 2);
    }

    public function getLineTotalAttribute(): float
    {
        return round($this->quantity * $this->net_cost_price, 2);
    }

    /** How much of this line can still be sent back to the supplier.
     *
     *  H8 INVARIANT CHANGE — received_quantity now means the NET quantity
     *  still held from this line: StockReturnController::supplierStore()
     *  reduces received_quantity by every returned quantity at return
     *  time (and recomputes the PO totals), so the returns have ALREADY
     *  been subtracted from this figure. Subtracting returns()->sum()
     *  again here would double-count them and understate what is still
     *  returnable, so remaining returnable is simply the net held
     *  quantity. The gross historical returned total is still available
     *  via returns()->sum('quantity') where a caller needs it (e.g. the
     *  line-delete block check). Always check this server-side before
     *  accepting a new return for the item.
     *
     *  v2.2 (R3) — LEGACY EXCEPTION: returns recorded BEFORE v2.0
     *  shipped (2026-10-29) never reduced received_quantity, because
     *  the H8 change did not exist yet. For those rows only, subtract
     *  them here — otherwise a pre-v2.0 return of 8 units on a line
     *  received 20 would show 20 returnable instead of 12, and the
     *  same goods could be returned a second time. Returns from
     *  2026-10-29 onward are already net in received_quantity and must
     *  NOT be subtracted again. CAVEAT: a return recorded on the
     *  install day itself (2026-10-29) before the v2.0 upgrade is
     *  treated as post-v2.0 by this cutoff — if any such return
     *  exists, check that line's remaining figure once by hand. */
    public function remainingReturnableQuantity(): float
    {
        $legacyReturned = (float) $this->returns()->where('created_at', '<', '2026-10-29')->sum('quantity');
        return max(0, (float) $this->received_quantity - $legacyReturned);
    }
}
