<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'phone', 'email', 'address', 'id_card_number',
        'current_address', 'permanent_address', 'image', 'is_active',
    ];

    public function purchaseOrders() { return $this->hasMany(PurchaseOrder::class); }

    /** Supplier dues, mirroring Customer::ledgerFigures() — but this is
     *  deliberately NOT yet a full ledger, and must not be presented as
     *  one. There is no supplier_payments table and no balance column on
     *  suppliers, so "paid" is always 0 and "due" currently equals the
     *  total of received purchase orders. Pending (not yet received)
     *  orders are reported separately and are NOT part of what is owed.
     *  Supplier returns (to_supplier) adjust stock only and are not
     *  netted here either. A real ledger still needs: a migration adding
     *  supplier_payments (and/or a suppliers.balance column), payment
     *  recording that updates it, and ReportController including supplier
     *  dues in Capital Liabilities — see CHANGES.txt for the exact list.
     *  Until then, treat "due" as "received PO value, before any supplier
     *  payments", not as a settled amount owed. */
    public function duesFigures(): array
    {
        $received = round((float) $this->purchaseOrders()->where('status', 'received')->sum('total'), 2);
        $pending = round((float) $this->purchaseOrders()->where('status', 'pending')->sum('total'), 2);
        $paid = 0.0; // No supplier_payments table exists yet (see above).
        return [
            'received_total' => $received,
            'pending_total' => $pending,
            'paid' => $paid,
            'due' => round($received - $paid, 2),
        ];
    }

    public function getImageUrlAttribute(): string
    {
        if ($this->image) {
            return asset('uploads/suppliers/' . $this->image);
        }
        return 'https://ui-avatars.com/api/?name=' . urlencode($this->name) . '&background=475569&color=fff&size=128';
    }
}
