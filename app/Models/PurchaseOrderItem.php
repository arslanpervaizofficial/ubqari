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

    /** How much of what was actually RECEIVED into stock (not necessarily
     *  what was originally ordered — suppliers often short/over-ship)
     *  hasn't already been sent back in an earlier, separate return. Always
     *  check this server-side before accepting a new return for the item. */
    public function remainingReturnableQuantity(): float
    {
        return max(0, $this->received_quantity - $this->returns()->sum('quantity'));
    }
}
