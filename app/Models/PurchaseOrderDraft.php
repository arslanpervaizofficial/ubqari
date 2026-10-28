<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Temporary, NOT-final purchase-order draft (Fix 1).
 *
 * A draft is deliberately NOT a row in `purchase_orders`:
 *  - it never appears in the PO list, so it can never be "Mark Received"
 *    by mistake;
 *  - it never appears in the Purchase Report (which reads
 *    `purchase_orders` directly);
 *  - it touches no stock, ever.
 *
 * Only "Create Purchase Order" creates a real PurchaseOrder (status
 * `pending`, then `received` on Mark Received). At that moment the
 * draft is deleted. One draft per user is kept — a later save updates
 * the same row.
 *
 * `items` is a plain JSON array of the create form's rows:
 *   [{product_id, quantity, cost_price, discount_percent,
 *     max_discount_percent}, ...]
 * Partial rows are allowed here on purpose: a draft is a user
 * mid-typing, not a validated order.
 */
class PurchaseOrderDraft extends Model
{
    protected $fillable = [
        'user_id',
        'supplier_id',
        'discount_percent',
        'items',
    ];

    protected $casts = [
        'discount_percent' => 'decimal:2',
        'items' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
