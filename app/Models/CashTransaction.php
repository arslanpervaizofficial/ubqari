<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashTransaction extends Model
{
    // SoftDeletes (deleted_at column is added by a separate migration —
    // see CHANGES.txt): deleting a transaction now moves it to Trash
    // semantics instead of erasing it, matching CashParty and the other
    // money records. Sums in CashParty::syncBalance() only count live
    // (non-trashed) transactions, so a trashed transaction correctly
    // stops affecting the balance. Until the migration lands, delete()
    // still hard-deletes.
    use SoftDeletes;

    protected $fillable = ['cash_party_id', 'type', 'amount', 'transaction_date', 'note', 'user_id'];

    protected $casts = [
        'transaction_date' => 'date',
    ];

    public function party() { return $this->belongsTo(CashParty::class, 'cash_party_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
