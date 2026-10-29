<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerPayment extends Model
{
    use SoftDeletes;

    protected $fillable = ['customer_id', 'type', 'amount', 'note', 'user_id'];

    public function customer() { return $this->belongsTo(Customer::class); }
    public function user() { return $this->belongsTo(User::class); }

    /** Undoes this entry's effect on the customer's credit_balance —
     *  a credit (payment received) had DECREASED the balance, so reversing
     *  it increases it back; a debit (charge) had increased it, so
     *  reversing it decreases it. Used both when editing an entry (reverse
     *  the old amount, then the new amount is re-applied) and when
     *  deleting one (moving it to Trash). Caller wraps this with the
     *  actual update/delete in a DB transaction.
     *
     *  Guard: a soft-deleted (trashed) parent customer resolves to null
     *  through this belongsTo (no withTrashed()), e.g. when a payment is
     *  restored while its customer is still in Trash. Dereferencing null
     *  used to fatal-error the whole restore; the effect is now skipped
     *  instead. That keeps the restore from crashing, but it means the
     *  balance effect is lost for that entry — Trash restore should
     *  restore the customer first (see CHANGES.txt), and the ledger page's
     *  syncBalance() is what eventually repairs the stored balance. */
    public function reverseEffect(): void
    {
        $customer = $this->customer;
        if (!$customer) {
            return;
        }
        if ($this->type === 'credit') {
            $customer->increment('credit_balance', $this->amount);
        } else {
            $customer->decrement('credit_balance', $this->amount);
        }
    }

    /** The exact inverse of reverseEffect() — used when a Credit/Debit
     *  entry is Restored from Trash, to re-apply the balance effect that
     *  was reversed when it was deleted. Must run BEFORE $payment->restore(),
     *  same ordering reason as Order::restoreCompletedEffects(). Same
     *  null-customer guard as reverseEffect(): a payment restored while
     *  its customer is still trashed skips its balance effect rather
     *  than crashing. */
    public function restoreEffect(): void
    {
        $customer = $this->customer;
        if (!$customer) {
            return;
        }
        if ($this->type === 'credit') {
            $customer->decrement('credit_balance', $this->amount);
        } else {
            $customer->increment('credit_balance', $this->amount);
        }
    }
}
