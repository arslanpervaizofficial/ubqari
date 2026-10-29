<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashParty extends Model
{
    // SoftDeletes (deleted_at column is added by a separate migration —
    // see CHANGES.txt): deleting a party must no longer hard-delete it
    // and cascade away its whole transaction history. A soft delete keeps
    // the history and lets Trash restore it, like every other money
    // record in the app. Until the migration lands, delete() still
    // hard-deletes — do not rely on Trash recovery before then.
    use SoftDeletes;

    protected $fillable = ['name', 'note', 'balance'];

    public function transactions() { return $this->hasMany(CashTransaction::class)->latest('transaction_date')->latest('id'); }

    /** Rebuilds the cached balance from live (non-trashed) transactions:
     *  balance = sum(borrows) − sum(repays). Same repair idea as
     *  Customer::syncBalance(). Callers (ledger page, every write) use
     *  this instead of trusting a manually incremented balance, so a
     *  failed or half-applied write cannot leave history and balance
     *  disagreeing permanently. Returns the recomputed balance. */
    public function syncBalance(): float
    {
        $borrows = (float) $this->transactions()->where('type', 'borrow')->sum('amount');
        $repays = (float) $this->transactions()->where('type', 'repay')->sum('amount');
        $balance = round($borrows - $repays, 2);
        if (abs((float) $this->balance - $balance) > 0.004) {
            $this->forceFill(['balance' => $balance])->save();
        }
        return $balance;
    }
}
