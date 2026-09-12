<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class AppSetting extends Model
{
    protected $fillable = ['menu_title', 'print_title'];

    /** Returns the one settings row, creating it with the defaults if it
     *  doesn't exist yet (e.g. right after this migration runs on an
     *  existing install). Cached for the request/short-lived, so every page
     *  load doesn't need its own query just to render the sidebar title. */
    public static function current(): self
    {
        return Cache::remember('app_settings.current', 3600, function () {
            return static::query()->firstOrCreate([], [
                'menu_title' => 'Ubqari POS',
                'print_title' => 'Ubqari POS',
            ]);
        });
    }

    /** Call after saving changes so the cached copy above doesn't keep
     *  serving the old title until the cache naturally expires. */
    public static function forget(): void
    {
        Cache::forget('app_settings.current');
    }
}
