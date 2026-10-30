<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A server-side review flag: something sync applied (or refused to
 *  apply silently) that a human should look at. Currently created
 *  only for products whose stock went negative after a push batch
 *  (SyncController). Open while resolved_at is null; sync itself
 *  never resolves a flag. */
class SyncFlag extends Model
{
    protected $fillable = [
        'table_name', 'client_uuid', 'reason', 'data', 'resolved_at',
    ];

    protected $casts = [
        'data' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function scopeUnresolved($query)
    {
        return $query->whereNull('resolved_at');
    }
}
