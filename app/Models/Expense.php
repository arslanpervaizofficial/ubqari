<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use SoftDeletes;

    protected $fillable = ['type', 'amount', 'category', 'note', 'expense_date', 'user_id'];

    protected $casts = [
        'expense_date' => 'date',
    ];

    public function user() { return $this->belongsTo(User::class); }
}
