<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseOrder extends Model
{
    use SoftDeletes;

    // v3.1.0 (H13): received_at must be mass-assignable, or the
    // receive() update that sets it alongside status is silently
    // dropped by the guard. The column itself already exists
    // (2026_10_29_000002 migration); the backfill of old received POs
    // is a separate migration, not this model.
    protected $fillable = ['supplier_id', 'status', 'subtotal', 'discount_percent', 'discount_amount', 'total', 'received_at'];

    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function items() { return $this->hasMany(PurchaseOrderItem::class); }

    /** Undoes the stock this PO added when it was received — takes back out
     *  exactly the received quantity per line, and removes the "purchase"
     *  stock-movement log lines it created. Mirrors
     *  Order::reverseCompletedEffects() so deleting a PO behaves the same
     *  way deleting a Sales Order does: the record moves to Trash AND the
     *  stock/history effect it had goes with it, rather than leaving stock
     *  permanently inflated by a purchase nobody can see a record of any
     *  more. Caller wraps this in a DB transaction.
     *
     *  Guarded against going negative: current `stock` is a single running
     *  number, not tracked per-batch, so if a sale has already gone out
     *  against the stock this PO brought in, blindly subtracting the
     *  received quantity here would drive stock negative with no record of
     *  why — exactly the "order was placed, then the PO it came from just
     *  vanished, so where did that quantity go?" problem. Refuse instead;
     *  the PO stays in place until stock is adjusted (e.g. via a return)
     *  so there's actually enough left to reverse. */
    public function reverseReceivedEffects(): void
    {
        // v3.1.0 (C2): own transaction + PO row lock (withTrashed, so
        // a trashed row locks on the restore path's inverse) + status
        // re-check on the locked row. The stock-sufficiency guard now
        // reads LOCKED product rows (sorted by product id, the
        // deadlock-safe order), not stale relation values — otherwise
        // a concurrent sale could slip between the guard and the
        // decrement. v3.1.0 (H7): the stock decrease is mirrored in
        // batches. A force-deleted product skips only its stock leg
        // (logged), never the movement deletion.
        DB::transaction(function () {
            $locked = static::withTrashed()->lockForUpdate()->find($this->id);
            if (!$locked || $locked->status !== 'received') {
                return;
            }

            $items = $locked->items()->with('product')->get()->sortBy('product_id')->values();

            $lockedProducts = [];
            foreach ($items as $item) {
                if ((float) $item->received_quantity <= 0 || !$item->product) {
                    continue;
                }
                $product = Product::lockForUpdate()->find($item->product_id);
                if (!$product) {
                    continue;
                }
                if ((float) $product->stock < (float) $item->received_quantity) {
                    throw new \RuntimeException(
                        "Can't delete PO #{$locked->id}: \"{$product->name}\" only has {$product->stock} in stock, but this PO brought in {$item->received_quantity} — some of it has already been sold. Reduce stock consumption (e.g. a return) before deleting this PO."
                    );
                }
                $lockedProducts[$item->product_id] = $product;
            }

            foreach ($items as $item) {
                if ((float) $item->received_quantity <= 0) {
                    continue;
                }
                $product = $lockedProducts[$item->product_id] ?? null;
                if (!$product) {
                    Log::warning('PO reversal skipped stock leg: product missing (force-deleted).', [
                        'purchase_order_id' => $locked->id, 'purchase_order_item_id' => $item->id, 'product_id' => $item->product_id, 'received_quantity' => $item->received_quantity,
                    ]);
                    continue;
                }
                $product->decrement('stock', $item->received_quantity);
                ProductBatch::mirrorStockDelta((int) $item->product_id, -(float) $item->received_quantity, ['record' => "PO #{$locked->id}"]);
            }

            StockMovement::where('purchase_order_id', $locked->id)->where('type', 'purchase')->delete();
        });
    }

    /** The exact inverse of reverseReceivedEffects() — used when a PO is
     *  Restored from Trash, to put back the stock it added when it was
     *  received. Must run BEFORE $purchaseOrder->restore(), same ordering
     *  reason as Order::restoreCompletedEffects(). */
    public function restoreReceivedEffects(): void
    {
        // v3.1.0 (C2/H7): same locked pattern as
        // reverseReceivedEffects() — own transaction, PO row locked
        // (withTrashed: restore runs while the row is still trashed),
        // status re-checked on the locked row, stock increase mirrored
        // in batches. A force-deleted product skips only its leg
        // (logged); the old code fatal-errored on a null product here.
        DB::transaction(function () {
            $locked = static::withTrashed()->lockForUpdate()->find($this->id);
            if (!$locked || $locked->status !== 'received') {
                return;
            }

            foreach ($locked->items()->with('product')->get() as $item) {
                if ((float) $item->received_quantity <= 0) {
                    continue;
                }
                if (!$item->product) {
                    Log::warning('PO restore skipped stock leg: product missing (force-deleted).', [
                        'purchase_order_id' => $locked->id, 'purchase_order_item_id' => $item->id, 'product_id' => $item->product_id, 'received_quantity' => $item->received_quantity,
                    ]);
                    continue;
                }
                $item->product->increment('stock', $item->received_quantity);
                ProductBatch::mirrorStockDelta((int) $item->product_id, (float) $item->received_quantity, ['record' => "PO #{$locked->id}"]);

                StockMovement::create([
                    'product_id' => $item->product_id,
                    'type' => 'purchase',
                    'quantity' => $item->received_quantity,
                    'reason' => "PO #{$locked->id} restored from Trash",
                    'user_id' => auth()->id(),
                    'purchase_order_id' => $locked->id,
                ]);
            }
        });
    }
}
