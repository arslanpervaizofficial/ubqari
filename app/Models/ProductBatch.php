<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
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

    /** v3.1.0 (H8) — last non-missing-table batch failure, if any.
     *  Batch helpers never throw, so a failure that is NOT a missing
     *  table is recorded here (plus a Log::warning with context) for
     *  the caller to surface as a reconciliation warning. The caller
     *  reads-and-clears it with takeLastBatchError() after its
     *  transaction. product.stock stays authoritative throughout. */
    public static ?string $lastBatchError = null;

    /** Returns the recorded batch error (if any) and clears it, so a
     *  later operation in the same request cannot re-report it. */
    public static function takeLastBatchError(): ?string
    {
        $error = static::$lastBatchError;
        static::$lastBatchError = null;
        return $error;
    }

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
     *  unbatched. Never throws.
     *
     *  v3.1.0 (H8) — $context identifies the record being processed
     *  (e.g. ['record' => 'Order #12']) so a failure can be logged
     *  with it. A missing product_batches table still degrades
     *  silently (not-yet-migrated install); ANY other failure is
     *  logged with the context AND recorded in $lastBatchError for
     *  the caller to surface as a reconciliation warning. */
    public static function consumeFifo(int $productId, float $qty, array $context = []): void
    {
        if (!Schema::hasTable('product_batches') || $qty <= 0) {
            return;
        }

        try {
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
            static::recordBatchFailure('consumeFifo', $productId, $qty, $context, $e);
        }
    }

    /** Put a customer-returned quantity back into batches. It tops up
     *  the earliest-expiry partially-consumed batches first (a batch is
     *  never topped above its original quantity), and any remainder —
     *  including the whole quantity when the product has no batches at
     *  all — goes into the "UNBATCHED" batch (created if needed, expiry
     *  null). Never throws. v3.1.0 (H8) — same $context / failure
     *  recording as consumeFifo(). */
    public static function restoreReturned(int $productId, float $qty, array $context = []): void
    {
        if (!Schema::hasTable('product_batches') || $qty <= 0) {
            return;
        }

        try {
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
            static::recordBatchFailure('restoreReturned', $productId, $qty, $context, $e);
        }
    }

    /** v3.1.0 (H7) — mirror one product.stock delta in batches, in the
     *  caller's transaction: a stock increase tops batches back up
     *  (restoreReturned), a stock decrease consumes them FIFO
     *  (consumeFifo). A zero (dust-level) delta mirrors nothing. The
     *  helpers never take remaining below 0 or above the batch's
     *  original quantity (UNBATCHED grows quantity and remaining
     *  together, so that row never exceeds its own quantity either).
     *  Batch attribution may differ from the original batch when the
     *  delta is a correction rather than a new receipt — the invariant
     *  kept here is the TOTAL remaining tracking the stock delta, not
     *  per-batch identity. Never throws (see the helpers). */
    public static function mirrorStockDelta(int $productId, float $delta, array $context = []): void
    {
        $delta = round($delta, 2);
        if ($delta > 0.004) {
            static::restoreReturned($productId, $delta, $context);
        } elseif ($delta < -0.004) {
            static::consumeFifo($productId, -$delta, $context);
        }
    }

    /** v3.1.0 (H8) — shared failure handling for the batch helpers. A
     *  missing-table failure (table dropped mid-call after the
     *  hasTable guard) degrades silently as before; anything else is
     *  logged with the caller's context and recorded for the caller
     *  to surface. Only the first failure in a request is kept, so a
     *  multi-line operation reports its earliest problem. */
    protected static function recordBatchFailure(string $helper, int $productId, float $qty, array $context, \Throwable $e): void
    {
        $message = $e->getMessage();
        $missingTable = $e instanceof \Illuminate\Database\QueryException
            && (str_contains($message, 'product_batches')
                && (str_contains($message, "doesn't exist")
                    || str_contains($message, 'no such table')
                    || str_contains($message, '42S02')));

        report($e);

        if ($missingTable) {
            return;
        }

        Log::warning("ProductBatch::{$helper} failed; product.stock stays authoritative, batches need reconciliation.", array_merge(
            ['product_id' => $productId, 'quantity' => $qty, 'error' => $message],
            $context
        ));

        if (static::$lastBatchError === null) {
            $record = $context['record'] ?? ("product #{$productId}");
            static::$lastBatchError = "Batch bookkeeping failed for {$record} ({$helper}): {$message}. Stock figures are correct; batch breakdown may need reconciliation.";
        }
    }
}
