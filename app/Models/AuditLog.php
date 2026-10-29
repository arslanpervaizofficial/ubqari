<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ubqari POS v3.0 — FEATURE 4 (Audit Log).
 *
 * One row per audited change. Rows are written only by
 * AppServiceProvider (model events + auth events) and are never edited
 * or observed themselves (see the recursion guard there), so this model
 * carries no observers, no SoftDeletes and no extra behaviour.
 *
 * The `model` column stores the SHORT class name (e.g. "Product",
 * "CustomerPayment"), not the fully qualified name — that is what the
 * filter dropdown and the index view display, and what the controller
 * validates filter input against.
 */
class AuditLog extends Model
{
    protected $fillable = [
        'user_id',
        'model',
        'model_id',
        'action',
        'old_values',
        'new_values',
        'ip',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function user()
    {
        // withTrashed(): users are soft-deletable, and deleting a user
        // must not hide who made an entry. user_id is nullOnDelete at
        // the database level, so this still resolves to null for a
        // force-deleted user — the view falls back to a label then.
        return $this->belongsTo(User::class)->withTrashed();
    }
}
