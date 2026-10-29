<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\StockMovement;

class Order extends Model
{
    use SoftDeletes;

    // v3.1.0 (L4): status, total, paid_amount and due_amount removed —
    // money/status transitions must never be mass-assignable. Call
    // sites now use forceFill() (POSController, OrderController, and
    // purgeStaleInProgress() below).
    protected $fillable = [
        'order_number', 'customer_id', 'user_id',
        'subtotal', 'line_discount_total', 'discount_percent', 'discount_amount',
        'payment_method', 'bank_name', 'transaction_id', 'is_quotation',
        // Split-payment breakdown persisted by POSController::complete()
        // (H23-part); columns are created by the migration package.
        'cash_amount', 'bank_amount',
        'original_completed_at',
    ];

    protected $casts = ['original_completed_at' => 'datetime'];

    public function items() { return $this->hasMany(OrderItem::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function cashier() { return $this->belongsTo(User::class, 'user_id'); }
    public function payments() { return $this->hasMany(OrderPayment::class); }

    /** Cleans up "in_progress" orders left behind when a cashier opens the
     *  POS screen (which immediately creates an order row so items can be
     *  added to it) and then never completes or explicitly holds it —
     *  browser closed, session timed out, etc. Normally POSController
     *  cleans these up the next time that same cashier opens the POS
     *  screen, but if they never come back the row is orphaned forever
     *  and clutters reports (as an "in_progress" walk-in sale that never
     *  really happened). Called from report/listing screens so admins
     *  don't have to think about it: carts that did have items are kept
     *  but demoted to "hold" (same rule POSController already uses) so
     *  nothing with real stock/customer implications is silently deleted.
     *  Only touches orders untouched for over an hour, so an order the
     *  cashier is actively using right now is never affected.
     *
     *  FIXED 2026-10-29 (v1.4): an EMPTY cart (no items, total 0.00, no
     *  payment, no stock movement, no customer) used to be soft-deleted
     *  with ->delete(), which is exactly why Trash filled up with
     *  0.00 walk-in orders nobody ever deleted — opening the POS alone
     *  creates the row, and the purge then "deleted" it into Trash. An
     *  empty cart has nothing worth restoring, so it is now permanently
     *  discarded with ->forceDelete() and never reaches Trash. Only
     *  user-initiated deletes (completed/held orders) still go to Trash. */
    public static function purgeStaleInProgress(): void
    {
        static::where('status', 'in_progress')
            ->where('updated_at', '<', now()->subHour())
            ->get()
            ->each(function (self $stale) {
                $stale->items()->count() > 0
                    ? $stale->forceFill(['status' => 'hold'])->save()
                    : $stale->forceDelete();
            });
    }

    /** Undoes everything completing this order did — puts the sold stock back,
     *  removes the "sale" stock-movement log lines it created, and reverses
     *  any amount it added to the customer's credit balance (due amount).
     *  Used both when reopening a completed order for editing and when
     *  permanently deleting one, so stock/ledger totals stay accurate
     *  either way. Caller decides what happens to the order row itself
     *  afterwards (status change / delete, in its own transaction).
     *
     *  v3.1.0 (C2): this method now runs in its own transaction (a
     *  savepoint when the caller already holds one), locks the order
     *  row with lockForUpdate and re-checks status on the LOCKED row —
     *  the old guard read only the in-memory status, so two concurrent
     *  reversals could both pass it. The locked row's figures (not the
     *  caller's possibly stale instance) drive every leg below.
     *  v3.1.0 (H7): the stock increase is mirrored in batches.
     *  v3.1.0 (L11c): a force-deleted (missing) product skips ONLY its
     *  stock leg, transparently (logged); the customer-credit leg is
     *  NEVER skipped for a missing product. */
    public function reverseCompletedEffects(): void
    {
        DB::transaction(function () {
            $locked = static::withTrashed()->lockForUpdate()->find($this->id);
            if (!$locked || $locked->status !== 'completed') {
                return;
            }

            // FIXED (H3): the full line quantity used to be added back even
            // when the customer had already returned part of it — and the
            // return had already put those units back on the shelf — so stock
            // was overstated by the returned quantity (e.g. sold 10, returned
            // 4, delete added back 10). Only the NET quantity still out
            // (sold minus already returned) is added back. Note: the linked
            // return records themselves are left in place (not voided) — that
            // is a deliberate product decision to record separately, not an
            // oversight; the stock math here is correct either way.
            foreach ($locked->items()->with('product')->get() as $item) {
                $netQty = max(0, $item->quantity - $item->returns()->sum('quantity'));
                if ($netQty <= 0) {
                    continue;
                }
                if (!$item->product) {
                    Log::warning('Order reversal skipped stock leg: product missing (force-deleted).', [
                        'order_id' => $locked->id, 'order_item_id' => $item->id, 'product_id' => $item->product_id, 'net_quantity' => $netQty,
                    ]);
                    continue;
                }
                $item->product->increment('stock', $netQty);
                ProductBatch::mirrorStockDelta((int) $item->product_id, (float) $netQty, ['record' => "Order #{$locked->id}"]);
            }

            StockMovement::where('order_id', $locked->id)->where('type', 'sale')->delete();

            // due_amount is signed for named customers (negative = they overpaid
            // and were carrying an advance), so any non-zero value is reversed.
            // This leg runs even when a product above was missing.
            if ($locked->customer_id && abs($locked->due_amount) > 0.004) {
                $locked->customer()->decrement('credit_balance', $locked->due_amount);
            }
        });
    }

    /** The exact inverse of reverseCompletedEffects() — used when an order is
     *  Restored from Trash, to put back the stock/ledger effects that were
     *  reversed when it was deleted. Order matters here: this must run
     *  BEFORE $order->restore(), while the row is still soft-deleted, so a
     *  page refresh mid-way never shows a "completed" order with its stock
     *  effects only half re-applied.
     *
     *  v3.1.0 (C2/L11g): own locked guard — the order row is locked
     *  (withTrashed, so a trashed row locks) and status re-checked
     *  inside this method's transaction, not just at the caller.
     *  v3.1.0 (H7): the stock decrease is mirrored in batches.
     *  v3.1.0 (L11c): a missing product skips only its stock leg
     *  (logged); the customer-credit leg still runs. A line that would
     *  drive stock negative refuses the whole restore (the Trash
     *  pre-check is only a stale-read early warning; this locked
     *  check is authoritative). */
    public function restoreCompletedEffects(): void
    {
        DB::transaction(function () {
            $locked = static::withTrashed()->lockForUpdate()->find($this->id);
            if (!$locked || $locked->status !== 'completed') {
                return;
            }

            // FIXED (H3): mirror of reverseCompletedEffects() — only the NET
            // quantity still out (sold minus already returned) is deducted
            // again, and the recreated sale movement carries that net
            // quantity, so the movement history matches the stock change. A
            // fully-returned line deducts nothing and writes no 0-qty
            // movement.
            $items = $locked->items()->with('product')->get()->sortBy('product_id')->values();

            // Guard pass on locked product rows, before anything mutates.
            $lockedProducts = [];
            foreach ($items as $item) {
                $netQty = max(0, $item->quantity - $item->returns()->sum('quantity'));
                if ($netQty <= 0 || !$item->product) {
                    continue;
                }
                $product = Product::lockForUpdate()->find($item->product_id);
                if (!$product) {
                    continue;
                }
                if ((float) $product->stock < (float) $netQty) {
                    throw new \RuntimeException(
                        "Can't restore order {$locked->order_number}: \"{$product->name}\" only has {$product->stock} in stock, "
                        . "but restoring this order would deduct {$netQty} again — some of it has already been sold again."
                    );
                }
                $lockedProducts[$item->product_id] = $product;
            }

            foreach ($items as $item) {
                $netQty = max(0, $item->quantity - $item->returns()->sum('quantity'));
                if ($netQty <= 0) {
                    continue;
                }
                $product = $lockedProducts[$item->product_id] ?? null;
                if (!$product) {
                    Log::warning('Order restore skipped stock leg: product missing (force-deleted).', [
                        'order_id' => $locked->id, 'order_item_id' => $item->id, 'product_id' => $item->product_id, 'net_quantity' => $netQty,
                    ]);
                    continue;
                }
                $product->decrement('stock', $netQty);
                ProductBatch::mirrorStockDelta((int) $item->product_id, -(float) $netQty, ['record' => "Order #{$locked->id}"]);
                StockMovement::create([
                    'product_id' => $item->product_id,
                    'type' => 'sale',
                    'quantity' => -$netQty,
                    'user_id' => auth()->id(),
                    'order_id' => $locked->id,
                ]);
            }

            if ($locked->customer_id && abs($locked->due_amount) > 0.004) {
                $locked->customer()->increment('credit_balance', $locked->due_amount);
            }
        });
    }
}
