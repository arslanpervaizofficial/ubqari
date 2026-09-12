<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductPriceHistory extends Model
{
    protected $fillable = ['product_id', 'old_price', 'new_price', 'changed_by'];

    public function product() { return $this->belongsTo(Product::class); }
    public function changedBy() { return $this->belongsTo(User::class, 'changed_by'); }
}
