<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'phone', 'email', 'address', 'id_card_number',
        'current_address', 'permanent_address', 'image', 'is_active',
    ];

    public function purchaseOrders() { return $this->hasMany(PurchaseOrder::class); }

    public function getImageUrlAttribute(): string
    {
        if ($this->image) {
            return asset('uploads/suppliers/' . $this->image);
        }
        return 'https://ui-avatars.com/api/?name=' . urlencode($this->name) . '&background=475569&color=fff&size=128';
    }
}
