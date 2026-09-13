<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        'name', 'sku', 'barcode', 'category', 'unit',
        'purchase_price', 'purchase_discount_percent', 'sale_price', 'max_discount_percent',
        'stock', 'min_stock', 'max_stock', 'is_active',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Units that must be sold in whole numbers (no decimals).
    public const INTEGER_UNITS = ['piece', 'pcs', 'dozen', 'box', 'pack', 'carton', 'unit'];

    public function priceHistory()
    {
        return $this->hasMany(ProductPriceHistory::class);
    }

    public function isLowStock(): bool
    {
        return $this->min_stock !== null && $this->stock <= $this->min_stock;
    }

    public function isIntegerUnit(): bool
    {
        return in_array(strtolower($this->unit), self::INTEGER_UNITS);
    }

    /** The actual cost per unit after the supplier's discount — this is
     *  what should drive Stock Value and Cost of Goods Sold everywhere,
     *  never the raw `purchase_price` alone. `purchase_price` is kept as
     *  the GROSS rate the supplier bills at (what you'd see on their price
     *  list, before their discount); `purchase_discount_percent` is that
     *  discount. Keeping them separate (rather than collapsing straight to
     *  a net figure) is what lets the Products form, the Excel
     *  import/export, and Purchase Order receiving all agree on what
     *  "Purchase Price" means. */
    public function getNetPurchasePriceAttribute(): float
    {
        return round($this->purchase_price * (1 - ($this->purchase_discount_percent ?? 0) / 100), 2);
    }

    /** Round a quantity according to this product's unit type. */
    public function roundQuantity(float $qty): float
    {
        return $this->isIntegerUnit() ? round($qty) : round($qty, 2);
    }
}
