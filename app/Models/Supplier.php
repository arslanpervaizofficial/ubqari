<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Supplier extends Model
{
    use SoftDeletes;

    // v3.1.0 (L4): balance removed — it is ledger-derived
    // (syncBalance()'s forceFill), never request mass assignment. No
    // controller create/update ever passed balance (rules() has no
    // such field), so no call-site change was needed for this model.
    protected $fillable = [
        'name', 'phone', 'email', 'address', 'id_card_number',
        'current_address', 'permanent_address', 'image', 'is_active',
    ];

    public function purchaseOrders() { return $this->hasMany(PurchaseOrder::class); }
    public function payments() { return $this->hasMany(SupplierPayment::class); }

    /** The one true formula for what WE owe this supplier, mirroring
     *  Customer::ledgerFigures() (but with the sign flipped to our side):
     *      balance = received PO totals
     *              - supplier payments (cash paid to the supplier)
     *              - supplier credits (credit notes / discounts granted)
     *              + supplier debits (extra charges by the supplier)
     *  Positive = we owe the supplier, negative = advance paid to them.
     *  Pending (not yet received) orders are reported separately and are
     *  NOT part of what is owed.
     *
     *  CRITICAL — no double-counting of supplier returns: v2.0 H8 already
     *  reduces a PO line's received_quantity (and recomputes the PO total)
     *  at supplier-return time, so the returned value is ALREADY out of
     *  the received totals above. Subtracting to_supplier returns here
     *  again would count every return twice. */
    public function duesFigures(): array
    {
        $received = round((float) $this->purchaseOrders()->where('status', 'received')->sum('total'), 2);
        $pending = round((float) $this->purchaseOrders()->where('status', 'pending')->sum('total'), 2);
        $paid = round((float) $this->payments()->where('type', 'payment')->sum('amount'), 2);
        $credits = round((float) $this->payments()->where('type', 'credit')->sum('amount'), 2);
        $debits = round((float) $this->payments()->where('type', 'debit')->sum('amount'), 2);
        // due comes from the stored balance column (kept current by
        // syncBalance() after every PO/payment/return event). If the
        // column is missing (migration not run yet) fall back to the
        // recomputed figure so the page still shows something sane.
        $due = $this->balance !== null
            ? round((float) $this->balance, 2)
            : round($received - $paid - $credits + $debits, 2);
        return [
            'received_total' => $received,
            'pending_total' => $pending,
            'paid' => round($paid + $credits, 2),
            'payments_total' => $paid,
            'credits' => $credits,
            'debits' => $debits,
            'due' => $due,
        ];
    }

    /** Re-saves balance from the formula above if it drifted — same
     *  pattern as Customer::syncBalance(). Callers (supplier page,
     *  payment recording, PO receive/correction/delete, supplier
     *  returns) call this after the event that moves the balance, so
     *  the stored column never silently disagrees with the ledger.
     *
     *  v3.1.0 (M10): when called inside a transaction (every PO /
     *  return / payment event path after this fix), the supplier row
     *  is locked first and this instance refreshed from the locked
     *  row, so two concurrent syncs cannot interleave their
     *  read-compute-write on the balance column. Outside a
     *  transaction (e.g. the detail page) locking would release
     *  immediately, so it is skipped there. withTrashed is used so a
     *  sync for a supplier that itself sits in Trash (Trash restore
     *  path) still locks and refreshes instead of failing. */
    public function syncBalance(): array
    {
        if (DB::transactionLevel() > 0) {
            $locked = static::withTrashed()->lockForUpdate()->find($this->id);
            if ($locked) {
                $this->setRawAttributes($locked->getAttributes(), true);
            }
        }
        $f = $this->duesFigures();
        $computed = round($f['received_total'] - $f['payments_total'] - $f['credits'] + $f['debits'], 2);
        if ($this->balance === null || abs((float) $this->balance - $computed) > 0.004) {
            $this->forceFill(['balance' => $computed])->save();
            $f['due'] = $computed;
        }
        return $f;
    }

    public function getImageUrlAttribute(): string
    {
        if ($this->image) {
            return asset('uploads/suppliers/' . $this->image);
        }
        return 'https://ui-avatars.com/api/?name=' . urlencode($this->name) . '&background=475569&color=fff&size=128';
    }
}
