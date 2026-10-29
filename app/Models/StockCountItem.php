<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockCountItem extends Model
{
    protected $fillable = [
        'stock_count_id', 'product_id',
        'snapshot_stock', 'counted_stock', 'variance',
    ];

    protected $casts = [
        'snapshot_stock' => 'decimal:2',
        'counted_stock' => 'decimal:2',
        'variance' => 'decimal:2',
    ];

    /**
     * Variance is a derived figure (counted - snapshot) and must never
     * be typed in or drift: recompute it on every save. A NULL counted
     * figure means "not counted yet", so variance is NULL too — never
     * 0, which would falsely claim the shelf matched the system.
     */
    protected static function booted(): void
    {
        static::saving(function (StockCountItem $item) {
            $item->variance = $item->counted_stock === null
                ? null
                : round((float) $item->counted_stock - (float) $item->snapshot_stock, 2);
        });
    }

    public function count()
    {
        return $this->belongsTo(StockCount::class, 'stock_count_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function isCounted(): bool
    {
        return $this->counted_stock !== null;
    }
}
