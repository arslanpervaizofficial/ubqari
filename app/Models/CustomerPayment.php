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
     *  actual update/delete in a DB transaction. */
    public function reverseEffect(): void
    {
        if ($this->type === 'credit') {
            $this->customer->increment('credit_balance', $this->amount);
        } else {
            $this->customer->decrement('credit_balance', $this->amount);
        }
    }

    /** The exact inverse of reverseEffect() — used when a Credit/Debit
     *  entry is Restored from Trash, to re-apply the balance effect that
     *  was reversed when it was deleted. Must run BEFORE $payment->restore(),
     *  same ordering reason as Order::restoreCompletedEffects(). */
    public function restoreEffect(): void
    {
        if ($this->type === 'credit') {
            $this->customer->decrement('credit_balance', $this->amount);
        } else {
            $this->customer->increment('credit_balance', $this->amount);
        }
    }
}
