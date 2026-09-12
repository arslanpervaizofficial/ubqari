<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    protected $fillable = [
        'order_id', 'product_id', 'quantity', 'unit_price',
        'discount_percent', 'discount_amount', 'line_total',
    ];

    public function order() { return $this->belongsTo(Order::class); }
    public function product() { return $this->belongsTo(Product::class); }
    public function returns() { return $this->hasMany(StockReturn::class); }

    /** How much of this line item hasn't already been returned in an
     *  earlier, separate return transaction. Always check this server-side
     *  before accepting a new return for the item — the quantity shown to
     *  the user when they load the order is just a starting suggestion. */
    public function remainingReturnableQuantity(): float
    {
        return max(0, $this->quantity - $this->returns()->sum('quantity'));
    }
}
