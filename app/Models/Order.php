<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\StockMovement;

class Order extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_number', 'customer_id', 'user_id', 'status',
        'subtotal', 'line_discount_total', 'discount_percent', 'discount_amount', 'total',
        'paid_amount', 'due_amount', 'payment_method', 'bank_name', 'transaction_id', 'is_quotation',
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
     *  don't have to think about it: empty carts are discarded, carts that
     *  did have items are kept but demoted to "hold" (same rule POSController
     *  already uses) so nothing with real stock/customer implications is
     *  silently deleted. Only touches orders untouched for over an hour,
     *  so an order the cashier is actively using right now is never
     *  affected. */
    public static function purgeStaleInProgress(): void
    {
        static::where('status', 'in_progress')
            ->where('updated_at', '<', now()->subHour())
            ->get()
            ->each(function (self $stale) {
                $stale->items()->count() > 0
                    ? $stale->update(['status' => 'hold'])
                    : $stale->delete();
            });
    }

    /** Undoes everything completing this order did — puts the sold stock back,
     *  removes the "sale" stock-movement log lines it created, and reverses
     *  any amount it added to the customer's credit balance (due amount).
     *  Used both when reopening a completed order for editing and when
     *  permanently deleting one, so stock/ledger totals stay accurate
     *  either way. Caller is responsible for wrapping this in a DB transaction
     *  and deciding what happens to the order row itself afterwards. */
    public function reverseCompletedEffects(): void
    {
        if ($this->status !== 'completed') {
            return;
        }

        foreach ($this->items()->with('product')->get() as $item) {
            $item->product->increment('stock', $item->quantity);
        }

        StockMovement::where('order_id', $this->id)->where('type', 'sale')->delete();

        if ($this->customer_id && $this->due_amount > 0) {
            $this->customer()->decrement('credit_balance', $this->due_amount);
        }
    }

    /** The exact inverse of reverseCompletedEffects() — used when an order is
     *  Restored from Trash, to put back the stock/ledger effects that were
     *  reversed when it was deleted. Order matters here: this must run
     *  BEFORE $order->restore(), while the row is still soft-deleted, so a
     *  page refresh mid-way never shows a "completed" order with its stock
     *  effects only half re-applied. */
    public function restoreCompletedEffects(): void
    {
        if ($this->status !== 'completed') {
            return;
        }

        foreach ($this->items()->with('product')->get() as $item) {
            $item->product->decrement('stock', $item->quantity);
            StockMovement::create([
                'product_id' => $item->product_id,
                'type' => 'sale',
                'quantity' => -$item->quantity,
                'user_id' => auth()->id(),
                'order_id' => $this->id,
            ]);
        }

        if ($this->customer_id && $this->due_amount > 0) {
            $this->customer()->increment('credit_balance', $this->due_amount);
        }
    }
}
