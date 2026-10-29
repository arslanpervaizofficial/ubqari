<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockCount extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_APPROVED = 'approved';

    protected $fillable = ['count_date', 'status', 'user_id', 'note'];

    protected $casts = [
        'count_date' => 'date',
    ];

    public function items()
    {
        return $this->hasMany(StockCountItem::class);
    }

    /** Only rows that actually have a counted figure (blank = not counted). */
    public function countedItems()
    {
        return $this->hasMany(StockCountItem::class)->whereNotNull('counted_stock');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }
}
