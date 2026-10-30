<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One row per device allowed to call the sync API. Only the hash of
 *  the token is stored (see sync:issue-token, which prints the token
 *  once at creation). The plain token value is never saved, never
 *  returned by any endpoint, and never logged. */
class SyncDeviceToken extends Model
{
    protected $fillable = [
        'device_id', 'name', 'token_hash', 'last_used_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
    ];
}
