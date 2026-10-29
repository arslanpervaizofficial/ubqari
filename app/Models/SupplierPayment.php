<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierPayment extends Model
{
    use SoftDeletes;

    protected $fillable = ['supplier_id', 'type', 'amount', 'note', 'user_id'];

    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function user() { return $this->belongsTo(User::class); }

    /** How this entry moves what WE owe the supplier (suppliers.balance):
     *  payment and credit both REDUCE what is owed (money paid out, or a
     *  credit note/discount granted by the supplier); a debit INCREASES
     *  it (an extra charge by the supplier). This mirrors
     *  CustomerPayment's pattern, with the sign flipped to the supplier's
     *  side of the ledger: for a customer, a credit is money received
     *  (they owe less); here, a payment/credit is money out or forgiven
     *  (we owe less).
     *
     *  reverseEffect() undoes the entry's effect — used when an entry is
     *  moved to Trash (delete). restoreEffect() re-applies it — used
     *  when an entry is restored from Trash. Both must run inside the
     *  caller's DB transaction, next to the actual delete/restore.
     *
     *  Guard: a soft-deleted (trashed) parent supplier resolves to null
     *  through this belongsTo (no withTrashed()), e.g. when a payment is
     *  restored while its supplier is still in Trash. The effect is
     *  skipped instead of fatal-erroring, exactly like
     *  CustomerPayment::reverseEffect()/restoreEffect() — and like there,
     *  the supplier page's syncBalance() is what eventually repairs the
     *  stored balance, so restore the supplier first. */
    public function reverseEffect(): void
    {
        $supplier = $this->supplier;
        if (!$supplier) {
            return;
        }
        if ($this->type === 'debit') {
            $supplier->decrement('balance', $this->amount);
        } else {
            // payment or credit: both had reduced what was owed.
            $supplier->increment('balance', $this->amount);
        }
    }

    /** The exact inverse of reverseEffect(). Must run BEFORE
     *  $payment->restore(), so the balance is correct the moment the
     *  row becomes live again. */
    public function restoreEffect(): void
    {
        $supplier = $this->supplier;
        if (!$supplier) {
            return;
        }
        if ($this->type === 'debit') {
            $supplier->increment('balance', $this->amount);
        } else {
            $supplier->decrement('balance', $this->amount);
        }
    }
}
