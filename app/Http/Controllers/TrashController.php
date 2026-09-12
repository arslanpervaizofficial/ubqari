<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\PurchaseOrder;
use App\Models\StockReturn;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrashController extends Controller
{
    /** Every soft-deletable record type, keyed by the slug used in the URL.
     *  "roles" governs who can VIEW and RESTORE that type's Trash — Users
     *  only an Admin, everything else Admin or Manager. Permanently
     *  deleting (force-delete) is always Admin-only regardless of this
     *  list, enforced by the route middleware in routes/web.php, and the
     *  Trash view hides those buttons for anyone else. */
    private const TYPES = [
        'customers' => ['model' => Customer::class, 'label' => 'Customers', 'roles' => ['admin', 'manager']],
        'suppliers' => ['model' => Supplier::class, 'label' => 'Suppliers', 'roles' => ['admin', 'manager']],
        'orders' => ['model' => Order::class, 'label' => 'Sales Orders', 'roles' => ['admin', 'manager']],
        'purchase-orders' => ['model' => PurchaseOrder::class, 'label' => 'Purchase Orders', 'roles' => ['admin', 'manager']],
        'stock-returns' => ['model' => StockReturn::class, 'label' => 'Stock Returns', 'roles' => ['admin', 'manager']],
        'users' => ['model' => User::class, 'label' => 'Users', 'roles' => ['admin']],
    ];

    /** Only the types the current user's role is allowed to manage. */
    private function availableTypes(): array
    {
        $role = auth()->user()->role;
        return array_filter(self::TYPES, fn ($t) => in_array($role, $t['roles'], true));
    }

    private function resolveModel(string $type): string
    {
        $types = $this->availableTypes();
        abort_unless(isset($types[$type]), 404);
        return $types[$type]['model'];
    }

    public function index()
    {
        $types = $this->availableTypes();
        $trashed = [];
        foreach ($types as $slug => $t) {
            /** @var Model $model */
            $model = $t['model'];
            $query = $model::onlyTrashed()->latest('deleted_at');
            if ($slug === 'orders') $query->with('customer');
            if ($slug === 'purchase-orders') $query->with('supplier');
            if ($slug === 'stock-returns') $query->with('product', 'customer', 'supplier');
            $trashed[$slug] = $query->get();
        }
        return view('trash.index', compact('types', 'trashed'));
    }

    /** Restores the checked rows. Sales orders get their stock/ledger
     *  effects re-applied first (see Order::restoreCompletedEffects), and a
     *  received purchase order gets the stock it added re-applied too (see
     *  PurchaseOrder::restoreReceivedEffects) — everything else is a plain
     *  restore, nothing else was changed when they were trashed. */
    public function restore(Request $request, string $type)
    {
        $model = $this->resolveModel($type);
        $ids = (array) $request->input('ids', []);
        $items = $model::onlyTrashed()->whereIn('id', $ids)->get();
        $this->restoreEach($items);
        return back()->with('status', count($items) . ' item(s) restored.');
    }

    public function restoreAll(string $type)
    {
        $model = $this->resolveModel($type);
        $items = $model::onlyTrashed()->get();
        $this->restoreEach($items);
        return back()->with('status', count($items) . ' item(s) restored.');
    }

    private function restoreEach($items): void
    {
        DB::transaction(function () use ($items) {
            foreach ($items as $item) {
                if ($item instanceof Order) {
                    $item->restoreCompletedEffects();
                } elseif ($item instanceof PurchaseOrder) {
                    $item->restoreReceivedEffects();
                } elseif ($item instanceof StockReturn) {
                    $item->restoreEffects();
                }
                $item->restore();
            }
        });
    }

    /** Permanently deletes the checked rows. If a row still has other
     *  records pointing at it (e.g. a supplier with purchase-order history)
     *  the database itself refuses the delete — that's caught per-row so
     *  one un-deletable row doesn't block the rest of the batch, and the
     *  user gets an honest count instead of a raw SQL error. */
    public function forceDelete(Request $request, string $type)
    {
        $model = $this->resolveModel($type);
        $ids = (array) $request->input('ids', []);
        $items = $model::onlyTrashed()->whereIn('id', $ids)->get();
        return back()->with('status', $this->forceDeleteEach($items));
    }

    public function forceDeleteAll(string $type)
    {
        $model = $this->resolveModel($type);
        $items = $model::onlyTrashed()->get();
        return back()->with('status', $this->forceDeleteEach($items));
    }

    private function forceDeleteEach($items): string
    {
        $ok = 0;
        $blocked = 0;
        foreach ($items as $item) {
            try {
                DB::transaction(fn () => $item->forceDelete());
                $ok++;
            } catch (QueryException $e) {
                $blocked++;
            }
        }

        $msg = "{$ok} item(s) permanently deleted.";
        if ($blocked) {
            $msg .= " {$blocked} couldn't be deleted because other records (orders, payments, history) still reference them — they're still in Trash, and safe to leave there.";
        }
        return $msg;
    }
}
