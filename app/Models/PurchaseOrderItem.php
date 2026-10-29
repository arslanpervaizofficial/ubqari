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
     *  updateItem() floor guard and the line-delete block check). Always
     *  check this server-side before accepting a new return for the item. */
    public function remainingReturnableQuantity(): float
    {
        return max(0, (float) $this->received_quantity);
    }
}
