<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashParty extends Model
{
    protected $fillable = ['name', 'note', 'balance'];

    public function transactions() { return $this->hasMany(CashTransaction::class)->latest('transaction_date')->latest('id'); }
}
