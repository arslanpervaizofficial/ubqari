<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Ubqari POS v3.0 — FEATURE 4 (Audit Log).
 *
 * Read-only index. Access is gated by the route, not here: the route
 * lives inside the existing `role:admin,manager` group (see
 * routes-fragment.txt), mirroring how every other admin/manager page
 * in routes/web.php is gated. There is no create / edit / delete —
 * audit rows are written only by AppServiceProvider and are never
 * modified through the UI.
 *
 * Filters (all optional):
 * - user:   a user id.
 * - model:  one of the observed models' short class names.
 * - action: created / updated / deleted / restored / login / logout.
 * - from / to: date range on created_at (inclusive, whole days).
 * A filter value outside the fixed lists (or an unparseable date) is
 * simply ignored rather than erroring — a filter page should never
 * bounce the user to an error/redirect for a bad query string.
 * Results are newest first, 20 per page, with query strings kept
 * across pagination (withQueryString()).
 */
class AuditLogController extends Controller
{
    public const MODELS = [
        'Product',
        'Customer',
        'Supplier',
        'PurchaseOrder',
        'Order',
        'StockReturn',
        'Expense',
        'CashParty',
        'CashTransaction',
        'User',
        'SupplierPayment',
        'CustomerPayment',
    ];

    public const ACTIONS = [
        'created',
        'updated',
        'deleted',
        'restored',
        'login',
        'logout',
    ];

    public function index(Request $request)
    {
        $userId = $request->input('user');
        $userId = is_numeric($userId) ? (int) $userId : null;

        $model = $request->input('model');
        $model = in_array($model, self::MODELS, true) ? $model : null;

        $action = $request->input('action');
        $action = in_array($action, self::ACTIONS, true) ? $action : null;

        // strtotime() check only: an unparseable date is ignored, and
        // whereDate() receives the raw Y-m-d string the date input
        // sends (the view repopulates it verbatim).
        $from = $request->input('from');
        $from = is_string($from) && strtotime($from) !== false ? $from : null;

        $to = $request->input('to');
        $to = is_string($to) && strtotime($to) !== false ? $to : null;

        $logs = AuditLog::with('user')
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($model, fn ($q) => $q->where('model', $model))
            ->when($action, fn ($q) => $q->where('action', $action))
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        // Dropdown source: users who actually have audit rows, so the
        // filter never offers a user with nothing to show. withTrashed()
        // because users are soft-deletable and a trashed user still has
        // history worth filtering by. A user id passed in the URL that
        // is not in this list still filters correctly — it just shows
        // as its id in the dropdown fallback in the view.
        $users = User::withTrashed()
            ->whereIn('id', AuditLog::select('user_id')->distinct()->whereNotNull('user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('audit_logs.index', [
            'logs' => $logs,
            'users' => $users,
            'models' => self::MODELS,
            'actions' => self::ACTIONS,
            'filters' => [
                'user' => $userId,
                'model' => $model,
                'action' => $action,
                'from' => $from,
                'to' => $to,
            ],
        ]);
    }
}
