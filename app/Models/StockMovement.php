<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $fillable = ['product_id', 'type', 'quantity', 'reason', 'user_id', 'order_id', 'purchase_order_id', 'stock_return_id'];

    public function product() { return $this->belongsTo(Product::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function purchaseOrder() { return $this->belongsTo(PurchaseOrder::class); }
    public function stockReturn() { return $this->belongsTo(StockReturn::class); }
}
