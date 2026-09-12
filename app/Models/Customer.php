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

    /** Falls back to a generated placeholder avatar if no image was uploaded. */
    public function getImageUrlAttribute(): string
    {
        if ($this->image) {
            return asset('uploads/customers/' . $this->image);
        }
        return 'https://ui-avatars.com/api/?name=' . urlencode($this->name) . '&background=1e293b&color=fff&size=128';
    }
}
