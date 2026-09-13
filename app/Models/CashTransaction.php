<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashTransaction extends Model
{
    protected $fillable = ['cash_party_id', 'type', 'amount', 'transaction_date', 'note', 'user_id'];

    protected $casts = [
        'transaction_date' => 'date',
    ];

    public function party() { return $this->belongsTo(CashParty::class, 'cash_party_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
