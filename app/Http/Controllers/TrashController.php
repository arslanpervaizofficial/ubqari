<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CashParty;
use App\Models\CustomerPayment;
use App\Models\Expense;
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
        'customer-payments' => ['model' => CustomerPayment::class, 'label' => 'Customer Ledger Entries', 'roles' => ['admin', 'manager']],
        'suppliers' => ['model' => Supplier::class, 'label' => 'Suppliers', 'roles' => ['admin', 'manager']],
        'orders' => ['model' => Order::class, 'label' => 'Sales Orders', 'roles' => ['admin', 'manager']],
        'purchase-orders' => ['model' => PurchaseOrder::class, 'label' => 'Purchase Orders', 'roles' => ['admin', 'manager']],
        'stock-returns' => ['model' => StockReturn::class, 'label' => 'Stock Returns', 'roles' => ['admin', 'manager']],
        'expenses' => ['model' => Expense::class, 'label' => 'Expenses', 'roles' => ['admin', 'manager']],
        'users' => ['model' => User::class, 'label' => 'Users', 'roles' => ['admin']],
        'cash-parties' => ['model' => CashParty::class, 'label' => 'Cash Parties', 'roles' => ['admin', 'manager']],
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
        $trashedCounts = [];
        foreach ($types as $slug => $t) {
            /** @var Model $model */
            $model = $t['model'];
            $query = $model::onlyTrashed()->latest('deleted_at');
            if ($slug === 'orders') $query->with('customer');
            if ($slug === 'purchase-orders') $query->with('supplier');
            if ($slug === 'stock-returns') $query->with('product', 'customer', 'supplier');
            if ($slug === 'customer-payments') $query->with('customer', 'user');
            // FIXED (v2, M16): this used to load EVERY trashed row of
            // all 8 types with ->get(), so the page degraded linearly
            // as Trash grew. Each type is now capped at the latest 200
            // rows for display, with the true total passed separately
            // so the tab still shows the real count. Restore and
            // force-delete are NOT affected: they operate on selected
            // ids (restore()/forceDelete()) or on the full trashed set
            // (restoreAll()/forceDeleteAll()), never on this capped
            // display list — so "Restore All" still restores rows that
            // are not shown on this page.
            $trashedCounts[$slug] = $model::onlyTrashed()->count();
            $trashed[$slug] = $query->limit(200)->get();
        }
        return view('trash.index', compact('types', 'trashed', 'trashedCounts'));
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
        try {
            $this->restoreEach($items);
        } catch (\RuntimeException $e) {
            // A refused restore (see restoreEach()) must come back as a
            // readable message, not a 500 page — nothing was changed,
            // because the refusal happens before any mutation.
            return back()->withErrors(['error' => $e->getMessage()]);
        }
        return back()->with('status', count($items) . ' item(s) restored.');
    }

    public function restoreAll(string $type)
    {
        $model = $this->resolveModel($type);
        $items = $model::onlyTrashed()->get();
        try {
            $this->restoreEach($items);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['error' => $e->getMessage()]);
        }
        return back()->with('status', count($items) . ' item(s) restored.');
    }

    /** Refuses a restore with a clear message instead of corrupting data
     *  or crashing, in two situations the old code got wrong:
     *
     *  FIXED (v2, H21) — stock guard on restore, mirroring the guard
     *  PurchaseOrder::reverseReceivedEffects() already has on delete:
     *  restoring a completed order re-deducts its full quantities, and
     *  restoring a to_supplier return re-deducts its quantity, so if
     *  those goods have since been sold again the restore would drive
     *  stock negative. Any line that would take stock below 0 now
     *  refuses the WHOLE restore batch before anything is mutated.
     *  (Restoring a received PO only ADDS stock, and restoring a
     *  from_customer return only adds stock, so neither needs this
     *  guard.) This is a pre-check, not a row lock: it matches the
     *  existing PO reverse guard's approach, and the restore itself
     *  still runs inside one transaction.
     *
     *  FIXED (v2, H22-part) — trashed/missing parent customer: the old
     *  code called restoreEffect()/restoreCompletedEffects() blindly.
     *  For a CustomerPayment that meant a fatal error on null (the
     *  belongsTo relation excludes soft-deleted parents); for an Order
     *  it meant a silent no-op (the increment UPDATE matches 0 rows),
     *  so the order came back but its credit restoration was quietly
     *  lost. Restoring a payment or an order whose customer is still in
     *  Trash — or no longer exists at all — is now blocked with a
     *  message telling the user to restore the customer first. */
    private function restoreEach($items): void
    {
        foreach ($items as $item) {
            if ($item instanceof Order) {
                if ($item->customer_id) {
                    if (Customer::onlyTrashed()->where('id', $item->customer_id)->exists()) {
                        throw new \RuntimeException("Can't restore order {$item->order_number}: its customer is still in Trash. Restore the customer first, then restore this order.");
                    }
                    if (!Customer::withTrashed()->where('id', $item->customer_id)->exists()) {
                        throw new \RuntimeException("Can't restore order {$item->order_number}: its customer no longer exists, so the order's credit effect cannot be re-applied.");
                    }
                }
                if ($item->status === 'completed') {
                    foreach ($item->items()->with('product')->get() as $line) {
                        if ($line->product && $line->product->stock < $line->quantity) {
                            throw new \RuntimeException("Can't restore order {$item->order_number}: \"{$line->product->name}\" only has {$line->product->stock} in stock, but restoring this order would deduct {$line->quantity} again — some of it has already been sold again. Sell or adjust stock first, or leave this order in Trash.");
                        }
                    }
                }
            } elseif ($item instanceof StockReturn) {
                if ($item->type === 'to_supplier' && $item->product && $item->product->stock < $item->quantity) {
                    throw new \RuntimeException("Can't restore this supplier return: \"{$item->product->name}\" only has {$item->product->stock} in stock, but restoring the return would deduct {$item->quantity} again.");
                }
            } elseif ($item instanceof CustomerPayment) {
                if ($item->customer_id) {
                    if (Customer::onlyTrashed()->where('id', $item->customer_id)->exists()) {
                        throw new \RuntimeException("Can't restore this ledger entry: its customer is still in Trash. Restore the customer first, then restore this entry.");
                    }
                    if (!Customer::withTrashed()->where('id', $item->customer_id)->exists()) {
                        throw new \RuntimeException("Can't restore this ledger entry: its customer no longer exists, so the entry's balance effect cannot be re-applied.");
                    }
                }
            }
        }

        DB::transaction(function () use ($items) {
            foreach ($items as $item) {
                if ($item instanceof Order) {
                    $item->restoreCompletedEffects();
                } elseif ($item instanceof PurchaseOrder) {
                    $item->restoreReceivedEffects();
                } elseif ($item instanceof StockReturn) {
                    $item->restoreEffects();
                } elseif ($item instanceof CustomerPayment) {
                    $item->restoreEffect();
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
