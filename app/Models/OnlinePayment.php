<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OnlinePayment extends Model
{
    protected $fillable = [
        'order_id', 'gateway', 'amount', 'status',
        'gateway_txn_id', 'request_payload', 'callback_payload', 'user_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'request_payload' => 'array',
        'callback_payload' => 'array',
    ];

    public function order() { return $this->belongsTo(Order::class); }
    public function user() { return $this->belongsTo(User::class); }

    public function isPending(): bool { return $this->status === 'pending'; }
    public function isPaid(): bool { return $this->status === 'paid'; }
}
