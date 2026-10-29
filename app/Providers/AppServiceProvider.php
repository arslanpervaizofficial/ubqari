<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\CashParty;
use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Models\PurchaseOrder;
use App\Models\StockReturn;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Ubqari POS v3.0 — FEATURE 4 (Audit Log).
 *
 * Base = original app/Providers/AppServiceProvider.php (it was empty
 * apart from the two method stubs); this file adds ONLY the audit
 * wiring in boot(). No other v3.0 worker touches this file.
 *
 * WHAT IS LOGGED
 * - Model events for: Product, Customer, Supplier, PurchaseOrder,
 *   Order, StockReturn, Expense, CashParty, CashTransaction, User,
 *   SupplierPayment, CustomerPayment, ProductBatch (v3.0.4: batch
 *   edits — changed batch_no / expiry_date — are audit-logged).
 *   - created:  new_values = the model's attributes.
 *   - updated:  old_values / new_values = CHANGED attributes only
 *     (getChanges() diffed against getOriginal() — never the whole
 *     row). A save that changes nothing but updated_at writes no row.
 *   - deleted:  old_values = attributes, new_values = null.
 *   - restored: old_values = null, new_values = attributes.
 * - Auth events: Login / Logout write action login / logout with the
 *   user from the event itself.
 * - `model` stores the SHORT class name (e.g. "Product"), matching
 *   what AuditLogController validates and the view displays.
 *
 * GUARDS (critical — read before touching this file)
 * - Model events are SKIPPED when running in console
 *   (app()->runningInConsole()) or when there is no authenticated
 *   user. Seeders, migrations, artisan commands and queue workers
 *   therefore never pollute the log, and unattributed rows never
 *   appear. Login/logout events are EXEMPT from this guard and always
 *   log (they use $event->user, because on logout auth()->user() is
 *   already null by the time the event fires).
 * - The AuditLog model itself is NEVER observed, and record() refuses
 *   an AuditLog instance outright — writing a log row must never
 *   trigger another log row (infinite recursion).
 * - Secrets are never logged: `password` and `remember_token` are
 *   stripped from old_values and new_values explicitly, even though
 *   User already hides them from arrays — getAttributes() returns raw
 *   attributes, so the $hidden list alone would NOT protect them.
 *
 * DELIBERATELY NOT OBSERVED (would flood the log with detail rows)
 * - StockMovement, OrderItem, PurchaseOrderItem, ProductBatch and any
 *   other high-volume detail rows. The parent record (Order,
 *   PurchaseOrder, StockReturn) is what the log tracks; its line items
 *   change many times per single business action.
 *
 * KNOWN NOISE (accepted, not a bug)
 * - Ledger sync helpers (e.g. Supplier::syncBalance()) save their
 *   parent model when a figure drifts, so a balance correction shows
 *   up as its own `updated` row with only the balance field changed.
 *   That is a real change and is kept, not filtered out.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Attributes that must never reach the audit log, even though
        // getAttributes() would return them raw.
        $sensitive = ['password', 'remember_token'];

        $clean = function (array $attributes) use ($sensitive): array {
            return array_diff_key($attributes, array_flip($sensitive));
        };

        // Shared writer for model events. $action is one of
        // created / updated / deleted / restored. Returns early under
        // the guards above; callers pass already-computed values.
        $record = function (Model $model, string $action, ?array $old, ?array $new) use ($clean): void {
            // Recursion guard: AuditLog is never observed, and this
            // refuses it anyway if that ever changes.
            if ($model instanceof AuditLog) {
                return;
            }

            // Critical guard: no console writes, no unattributed rows.
            if (app()->runningInConsole() || !auth()->check()) {
                return;
            }

            AuditLog::create([
                'user_id' => auth()->id(),
                'model' => class_basename($model),
                'model_id' => $model->getKey(),
                'action' => $action,
                'old_values' => $old !== null ? $clean($old) : null,
                'new_values' => $new !== null ? $clean($new) : null,
                'ip' => request()->ip(),
            ]);
        };

        // Register the four events for one model class.
        $observe = function (string $class) use ($record): void {
            $class::created(function (Model $model) use ($record): void {
                $record($model, 'created', null, $model->getAttributes());
            });

            $class::updated(function (Model $model) use ($record): void {
                // Changed attributes only: getChanges() holds the new
                // values, getOriginal($key) the previous ones. Ignore
                // updated_at so a touch-only save writes no row.
                $changes = $model->getChanges();
                unset($changes['updated_at']);

                if ($changes === []) {
                    return;
                }

                $old = [];
                foreach ($changes as $key => $value) {
                    $old[$key] = $model->getOriginal($key);
                }

                $record($model, 'updated', $old, $changes);
            });

            $class::deleted(function (Model $model) use ($record): void {
                $record($model, 'deleted', $model->getAttributes(), null);
            });

            // FIX v3.0.1: restored() as a static registration method ONLY
            // exists on models that use the SoftDeletes trait (it is
            // defined in SoftDeletes itself, not in HasEvents). Calling
            // Product::restored() — Product has no SoftDeletes trait —
            // throws "Call to undefined method Product::restored()" at
            // boot, which kills EVERY artisan command and every request.
            // Product has no SoftDeletes trait, so it is skipped
            // here; its restored event could never fire anyway. (On a
            // v2.2 install CashParty/CashTransaction DO have the trait
            // and pass this check; the check decides at runtime, so
            // either way is safe.)
            if (in_array(SoftDeletes::class, class_uses_recursive($class))) {
                $class::restored(function (Model $model) use ($record): void {
                    $record($model, 'restored', null, $model->getAttributes());
                });
            }
        };

        foreach ([
            Product::class,
            ProductBatch::class,
            Customer::class,
            Supplier::class,
            PurchaseOrder::class,
            Order::class,
            StockReturn::class,
            Expense::class,
            CashParty::class,
            CashTransaction::class,
            User::class,
            SupplierPayment::class,
            CustomerPayment::class,
        ] as $class) {
            $observe($class);
        }

        // Auth events — ALWAYS logged, exempt from the console /
        // no-authenticated-user guard above. The user comes from the
        // event itself: on logout, auth()->user() is already null.
        $recordAuth = function (string $action, $user): void {
            if (!$user) {
                return;
            }

            AuditLog::create([
                'user_id' => $user->getKey(),
                'model' => 'User',
                'model_id' => $user->getKey(),
                'action' => $action,
                'old_values' => null,
                'new_values' => null,
                'ip' => app()->runningInConsole() ? null : request()->ip(),
            ]);
        };

        Event::listen(Login::class, function (Login $event) use ($recordAuth): void {
            $recordAuth('login', $event->user);
        });

        Event::listen(Logout::class, function (Logout $event) use ($recordAuth): void {
            $recordAuth('logout', $event->user);
        });
    }
}
