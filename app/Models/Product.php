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

    /** Round a quantity according to this product's unit type. */
    public function roundQuantity(float $qty): float
    {
        return $this->isIntegerUnit() ? round($qty) : round($qty, 2);
    }
}
