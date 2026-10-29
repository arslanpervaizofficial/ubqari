<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    protected $fillable = [
        'entry_date', 'reference', 'description',
        'source_type', 'source_id', 'user_id',
    ];

    protected $casts = [
        'entry_date' => 'date',
    ];

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Sum of the entry's debit lines (rounded to cents). */
    public function totalDebit(): float
    {
        return round((float) $this->lines->sum('debit'), 2);
    }

    /** Sum of the entry's credit lines (rounded to cents). */
    public function totalCredit(): float
    {
        return round((float) $this->lines->sum('credit'), 2);
    }

    /** True when the entry balances (debits equal credits to the cent). */
    public function isBalanced(): bool
    {
        return abs($this->totalDebit() - $this->totalCredit()) <= 0.004;
    }
}
