<?php

namespace App\Providers;

use App\Models\CashTransaction;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PurchaseOrder;
use App\Models\SupplierPayment;
use App\Observers\CashTransactionObserver;
use App\Observers\CustomerPaymentObserver;
use App\Observers\ExpenseObserver;
use App\Observers\OrderObserver;
use App\Observers\OrderPaymentObserver;
use App\Observers\PurchaseOrderObserver;
use App\Observers\SupplierPaymentObserver;
use Illuminate\Support\ServiceProvider;

/**
 * Ubqari POS v3.0 — Feature 6 (Full Accounting).
 *
 * Registers the journal-posting model observers. This is the ONLY
 * wiring this feature needs besides the routes (see
 * routes-fragment.txt).
 *
 * COORDINATOR: register this provider — this file does NOT touch
 * bootstrap/providers.php itself. Add to bootstrap/providers.php:
 *
 *     App\Providers\AccountingServiceProvider::class,
 *
 * Until that line is added, NO journal entry is posted automatically
 * (the controller pages and the opening-balance form still work, but
 * the journal will stay empty). No existing controller or model was
 * edited to achieve this: observers are the whole trigger mechanism.
 */
class AccountingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Order::observe(OrderObserver::class);
        Expense::observe(ExpenseObserver::class);
        PurchaseOrder::observe(PurchaseOrderObserver::class);
        SupplierPayment::observe(SupplierPaymentObserver::class);
        CustomerPayment::observe(CustomerPaymentObserver::class);
        CashTransaction::observe(CashTransactionObserver::class);
        // v3.1.0 (H12): post-completion order-ledger credits.
        OrderPayment::observe(OrderPaymentObserver::class);
    }
}
