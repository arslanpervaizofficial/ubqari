<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Worker — bill attribution only. NOT a login account.
 *
 *  A worker has no password, no role, and performs no action in the
 *  system. The only purpose of a worker row is its code: typed at
 *  billing time (web POS checkout, or the desktop billing screen) it
 *  stamps the order with worker_code + worker_name, which is what the
 *  printed bill shows as "Billed by". Staff users (admin/manager/
 *  cashier) are the ones who log in and act — see User. The two
 *  concepts share nothing except the shop they belong to.
 *
 *  No SoftDeletes: deleting a worker is safe because orders carry the
 *  code and name as denormalized text, never a foreign key. */
class Worker extends Model
{
    protected $fillable = [
        'name', 'code', 'is_active',
        'phone', 'cnic', 'email', 'image',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** Photo URL, same pattern as Customer: uploaded photo, else a
     *  generated placeholder avatar with the worker's initial. */
    public function getImageUrlAttribute(): string
    {
        if ($this->image) {
            return asset('uploads/workers/' . $this->image);
        }
        return 'https://ui-avatars.com/api/?name=' . urlencode($this->name) . '&background=1e293b&color=fff&size=128';
    }
}
