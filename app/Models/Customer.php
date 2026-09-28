<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'phone', 'email', 'address', 'id_card_number',
        'current_address', 'permanent_address', 'image',
        'discount_percent', 'credit_balance', 'is_active',
    ];

    public function orders() { return $this->hasMany(Order::class); }
    public function payments() { return $this->hasMany(CustomerPayment::class); }

    /** The one true formula for what this customer owes:
     *      balance = (completed orders: total - paid)   [negative if overpaid]
     *              + manual debits - credits            [customer_payments]
     *  Positive = customer owes us, negative = advance/credit owed to them.
     *  Returned as an array so the ledger page can show every part. */
    public function ledgerFigures(): array
    {
        $orders = $this->orders()->where('status', 'completed');
        $billed = round((float) (clone $orders)->sum('total'), 2);
        $orderPaid = round((float) (clone $orders)->sum('paid_amount'), 2);
        $credits = round((float) $this->payments()->where('type', 'credit')->sum('amount'), 2);
        $debits = round((float) $this->payments()->where('type', 'debit')->sum('amount'), 2);
        $orderDue = round($billed - $orderPaid, 2);

        return [
            'total_billed' => $billed,
            'order_paid' => $orderPaid,
            'order_due' => $orderDue,
            'credits' => $credits,
            'debits' => $debits,
            'total_paid' => round($orderPaid + $credits, 2),
            'balance' => round($orderDue + $debits - $credits, 2),
        ];
    }

    /** Re-saves credit_balance from ledgerFigures() if it drifted. */
    public function syncBalance(): array
    {
        $f = $this->ledgerFigures();
        if (abs((float) $this->credit_balance - $f['balance']) > 0.004) {
            $this->forceFill(['credit_balance' => $f['balance']])->save();
        }
        return $f;
    }

    /** Falls back to a generated placeholder avatar if no image was uploaded. */
    public function getImageUrlAttribute(): string
    {
        if ($this->image) {
            return asset('uploads/customers/' . $this->image);
        }
        return 'https://ui-avatars.com/api/?name=' . urlencode($this->name) . '&background=1e293b&color=fff&size=128';
    }
}
