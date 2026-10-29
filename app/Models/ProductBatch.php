<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Ubqari v3.0 — FEATURE 1 (Expiry + Batch Tracking).
 *
 * One row = one received batch of a product. product.stock stays the
 * authority for sellable quantity; remaining_quantity is the not-yet-
 * sold part of this batch. Batches are consumed earliest-expiry-first
 * (FIFO) and topped back up by customer returns — both helpers below
 * are deliberately total-failure-proof: they never throw, because a
 * batch problem must never block a sale or a return (the caller also
 * guards with a hasTable check, so a not-yet-migrated install keeps
 * working exactly as before).
 */
class ProductBatch extends Model
{
    protected $fillable = [
        'product_id', 'batch_no', 'expiry_date',
        'quantity', 'remaining_quantity', 'purchase_order_id',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'quantity' => 'decimal:2',
        'remaining_quantity' => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** Batches that need attention: an expiry date set, expiring within
     *  the next 30 days, with stock still left in the batch. Already-
     *  expired batches with stock left are included on purpose — they
     *  are the most urgent rows, not rows to hide. Null-expiry batches
     *  (e.g. "UNBATCHED") never appear here. */
    public function scopeExpiringSoon(Builder $query): Builder
    {
        return $query->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays(30)->toDateString())
            ->where('remaining_quantity', '>', 0);
    }

    /** FIFO order: earliest expiry first, batches with no expiry last,
     *  ties broken by oldest batch first. */
    public function scopeFifo(Builder $query): Builder
    {
        return $query->orderByRaw('CASE WHEN expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expiry_date')
            ->orderBy('id');
    }

    /** Decrement batches FIFO for a sale. $qty is the quantity already
     *  taken off product.stock by the caller. If total batched remaining
     *  is short (old unbatched stock exists), only what batched stock
     *  exists is decremented and the remainder is silently treated as
     *  unbatched. Never throws. */
    public static function consumeFifo(int $productId, float $qty): void
    {
        try {
            if (!Schema::hasTable('product_batches') || $qty <= 0) {
                return;
            }

            $left = $qty;
            $batches = static::where('product_id', $productId)
                ->where('remaining_quantity', '>', 0)
                ->fifo()
                ->lockForUpdate()
                ->get();

            foreach ($batches as $batch) {
                if ($left <= 0) {
                    break;
                }
                $take = min((float) $batch->remaining_quantity, $left);
                if ($take <= 0) {
                    continue;
                }
                $batch->decrement('remaining_quantity', $take);
                $left = round($left - $take, 2);
            }
            // Any $left still standing here is unbatched stock — the
            // caller already decremented product.stock for the full
            // quantity, so there is nothing further to do.
        } catch (\Throwable $e) {
            // Batch bookkeeping must never break a sale. product.stock
            // (already decremented by the caller) stays authoritative.
            report($e);
        }
    }

    /** Put a customer-returned quantity back into batches. It tops up
     *  the earliest-expiry partially-consumed batches first (a batch is
     *  never topped above its original quantity), and any remainder —
     *  including the whole quantity when the product has no batches at
     *  all — goes into the "UNBATCHED" batch (created if needed, expiry
     *  null). Never throws. */
    public static function restoreReturned(int $productId, float $qty): void
    {
        try {
            if (!Schema::hasTable('product_batches') || $qty <= 0) {
                return;
            }

            $left = $qty;
            $partial = static::where('product_id', $productId)
                ->whereColumn('remaining_quantity', '<', 'quantity')
                ->fifo()
                ->lockForUpdate()
                ->get();

            foreach ($partial as $batch) {
                if ($left <= 0) {
                    break;
                }
                $room = round((float) $batch->quantity - (float) $batch->remaining_quantity, 2);
                if ($room <= 0) {
                    continue;
                }
                $add = min($room, $left);
                $batch->increment('remaining_quantity', $add);
                $left = round($left - $add, 2);
            }

            if ($left > 0) {
                $unbatched = static::where('product_id', $productId)
                    ->where('batch_no', 'UNBATCHED')
                    ->lockForUpdate()
                    ->first();
                if ($unbatched) {
                    $unbatched->increment('quantity', $left);
                    $unbatched->increment('remaining_quantity', $left);
                } else {
                    static::create([
                        'product_id' => $productId,
                        'batch_no' => 'UNBATCHED',
                        'expiry_date' => null,
                        'quantity' => $left,
                        'remaining_quantity' => $left,
                        'purchase_order_id' => null,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Batch bookkeeping must never break a return. product.stock
            // (already incremented by the caller) stays authoritative.
            report($e);
        }
    }
}
