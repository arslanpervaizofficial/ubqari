<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\CashManagementController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\POSController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\StockReturnController;
use App\Http\Controllers\TrashController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\AccountingController;
use App\Http\Controllers\AgingController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\OnlinePaymentController;
use App\Http\Controllers\SmsLogController;
use App\Http\Controllers\StockCountController;
use Illuminate\Support\Facades\Route;

// ---------- Guest ----------
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    // H26: throttle login attempts — 5 tries per minute per IP. This is the
    // only throttling layer for login (no RateLimiter in LoginController).
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:5,1');
});

Route::get('/', fn () => redirect()->route('pos.index'));

// v3.0 FEATURE 9 — online payment callbacks: OUTSIDE every middleware group.
// Gateways post server-to-server with no shop session, so these must not sit
// behind 'auth', and they are CSRF-exempt in bootstrap/app.php.
Route::post('online-payments/callback/jazzcash', [OnlinePaymentController::class, 'jazzcashCallback'])
    ->name('online-payments.callback.jazzcash');
Route::post('online-payments/callback/easypaisa', [OnlinePaymentController::class, 'easypaisaCallback'])
    ->name('online-payments.callback.easypaisa');

// ---------- Authenticated ----------
Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
    // Carry-over from the separate logout 405 patch (v2.2 era): GET /logout
    // bhi logout kar de, warna v3.0 install karne par 405 wapas aa jata.
    // GET route ka naam nahi rakha taake route('logout') POST wali hi rahe.
    Route::get('/logout', [LoginController::class, 'logout']);

    // v3.0 FEATURE 8 — locale switch (EN | اردو toggle in the layout).
    // GET that only writes session('locale'); accepts en/ur only.
    Route::get('/locale/{locale}', function (string $locale) {
        if (in_array($locale, ['en', 'ur'], true)) {
            session(['locale' => $locale]);
        }

        return redirect()->back();
    })->name('locale.switch');
    // H28: the dashboard shows whole-business sales, so it is no longer
    // auth-only — hiding the sidebar link was never access control.
    Route::middleware('role:admin,manager')->get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/pos-product-search', [POSController::class, 'productSearch'])->name('pos.product-search');

    // Self-service settings (any logged-in user) — name/password only, email locked.
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // POS billing screen
    Route::prefix('pos')->name('pos.')->group(function () {
        Route::get('/', [POSController::class, 'index'])->name('index');
        Route::get('/held', [POSController::class, 'heldIndex'])->name('held-index');
        Route::get('/held/{order}', [POSController::class, 'showHeld'])->name('held-detail');
        Route::post('/new', [POSController::class, 'newOrder'])->name('new');
        Route::post('/resume/{order}', [POSController::class, 'resume'])->name('resume');
        Route::post('/add-item', [POSController::class, 'addItem'])->name('add-item');
        Route::delete('/item/{item}', [POSController::class, 'removeItem'])->name('remove-item');
        Route::patch('/item/{item}', [POSController::class, 'updateItemQuantity'])->name('update-item');
        Route::patch('/item/{item}/discount', [POSController::class, 'updateItemDiscount'])->name('update-item-discount');
        Route::post('/set-customer', [POSController::class, 'setCustomer'])->name('set-customer');
        Route::post('/set-discount', [POSController::class, 'setOverallDiscount'])->name('set-discount');
        Route::post('/{order}/complete', [POSController::class, 'complete'])->name('complete');
        // A plain browser refresh right after completing an order re-sends
        // the page's last request, which for this URL was the POST above —
        // but some browsers (and "open link in new tab"/back-forward cache)
        // replay it as a GET instead, which used to hit Laravel's default
        // 405 error page. Catch that here and just send them somewhere
        // useful instead of an error screen: the invoice if the order went
        // through, otherwise back to the register.
        Route::get('/{order}/complete', function (\App\Models\Order $order) {
            return $order->status === 'completed'
                ? redirect()->route('pos.invoice', $order)
                : redirect()->route('pos.index');
        });
        Route::post('/{order}/quotation', [POSController::class, 'saveAsQuotation'])->name('quotation');
        Route::post('/{order}/cancel', [POSController::class, 'cancel'])->name('cancel');
        Route::get('/{order}/invoice', [POSController::class, 'invoice'])->name('invoice');
        Route::post('/{order}/reorder', [POSController::class, 'reorder'])->name('reorder');

        // Load a completed order back into the billing screen for editing
        // (previous order id field). Admin/manager only — reverses the
        // order's stock/ledger effects so it can be safely re-completed.
        Route::middleware('role:admin,manager')->post('/load-order', [POSController::class, 'loadOrderForEdit'])->name('load-order');
    });

    // Admin + Manager only — everything EXCEPT deleting/permanently-deleting
    // a record. Delete access lives in its own admin-only group further
    // below, so a Manager can create/edit/view but never remove data.
    Route::middleware('role:admin,manager')->group(function () {
        Route::resource('products', ProductController::class)->except(['show', 'destroy']);
        Route::post('products/{product}/reactivate', [ProductController::class, 'reactivate'])->name('products.reactivate');
        Route::post('products/reactivate-selected', [ProductController::class, 'reactivateSelected'])->name('products.reactivate-selected');
        Route::post('products/reactivate-all', [ProductController::class, 'reactivateAll'])->name('products.reactivate-all');
        Route::get('products/{product}/price-history', [ProductController::class, 'priceHistory'])->name('products.price-history');
        Route::get('products-generate-barcode', [ProductController::class, 'generateBarcode'])->name('products.generate-barcode');
        Route::get('products-export', [ProductController::class, 'export'])->name('products.export');
        Route::post('products-import', [ProductController::class, 'import'])->name('products.import');

        Route::resource('customers', CustomerController::class)->except(['show', 'destroy']);
        Route::get('customers/{customer}/ledger', [CustomerController::class, 'ledger'])->name('customers.ledger');
        Route::post('customers/{customer}/payment', [CustomerController::class, 'recordPayment'])->name('customers.record-payment');
        Route::patch('customers/{customer}/payment/{payment}', [CustomerController::class, 'updatePayment'])->name('customers.update-payment');
        Route::post('customers/{customer}/toggle-active', [CustomerController::class, 'toggleActive'])->name('customers.toggle-active');

        Route::resource('suppliers', SupplierController::class)->except(['show', 'destroy']);
        Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
        Route::post('suppliers/{supplier}/toggle-active', [SupplierController::class, 'toggleActive'])->name('suppliers.toggle-active');
        // v2.2 supplier ledger: payment/credit/debit recording (admin/manager).
        Route::post('suppliers/{supplier}/payments', [SupplierController::class, 'recordPayment'])->name('suppliers.payments.store');

        Route::resource('purchase-orders', PurchaseOrderController::class)->except(['show', 'edit', 'update', 'destroy']);
        Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('purchase-orders.show');
        Route::patch('purchase-orders/{purchaseOrder}/items/{item}', [PurchaseOrderController::class, 'updateItem'])->name('purchase-orders.update-item');
        Route::patch('purchase-orders/{purchaseOrder}/discount', [PurchaseOrderController::class, 'updateDiscount'])->name('purchase-orders.update-discount');
        Route::get('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receiveForm'])->name('purchase-orders.receive-form');
        Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->name('purchase-orders.receive');
        // v1.6 routes (previously paste-only instructions, now in the file):
        // temporary draft autosave, and deleting one line of a purchase
        // order (guarded inside destroyItem: only when the line has no sale).
        Route::post('purchase-orders/draft', [PurchaseOrderController::class, 'storeDraft'])->name('purchase-orders.draft');
        Route::delete('purchase-orders/{purchaseOrder}/items/{item}', [PurchaseOrderController::class, 'destroyItem'])->name('purchase-orders.items.destroy');
        Route::get('products-ajax-search', [ProductController::class, 'ajaxSearch'])->name('products.ajax-search');

        // Sales-order history: search/filter, edit (loads into POS billing).
        Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
        Route::post('orders/{order}/edit', [OrderController::class, 'edit'])->name('orders.edit');
        Route::get('orders/{order}/ledger', [OrderController::class, 'ledger'])->name('orders.ledger');
        Route::post('orders/{order}/change-date', [OrderController::class, 'changeDate'])->name('orders.change-date');
        Route::post('orders/{order}/payment', [OrderController::class, 'recordPayment'])->name('orders.record-payment');

        Route::get('stock-returns', [StockReturnController::class, 'index'])->name('stock-returns.index');
        Route::get('stock-returns/customer', [StockReturnController::class, 'customerForm'])->name('stock-returns.customer-form');
        Route::post('stock-returns/customer', [StockReturnController::class, 'customerStore'])->name('stock-returns.customer-store');
        Route::get('stock-returns/lookup-order', [StockReturnController::class, 'lookupOrder'])->name('stock-returns.lookup-order');
        Route::get('stock-returns/supplier', [StockReturnController::class, 'supplierForm'])->name('stock-returns.supplier-form');
        Route::post('stock-returns/supplier', [StockReturnController::class, 'supplierStore'])->name('stock-returns.supplier-store');
        Route::get('stock-returns/lookup-purchase-order', [StockReturnController::class, 'lookupPurchaseOrder'])->name('stock-returns.lookup-purchase-order');

        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/cash-reconciliation', [ReportController::class, 'cashReconciliation'])->name('reports.cash-reconciliation');
        Route::get('reports/stock', [ReportController::class, 'stock'])->name('reports.stock');
        Route::get('reports/purchases', [ReportController::class, 'purchases'])->name('reports.purchases');
        Route::get('reports/capital', [ReportController::class, 'capital'])->name('reports.capital');
        Route::get('reports/customers', [ReportController::class, 'customers'])->name('reports.customers');
        Route::get('reports/customers/walk-in', [ReportController::class, 'walkInDetail'])->name('reports.walk-in-detail');
        Route::get('reports/customers/{customer}', [ReportController::class, 'customerDetail'])->name('reports.customer-detail');

        Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
        Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');

        Route::get('cash-management', [CashManagementController::class, 'index'])->name('cash-management.index');
        Route::post('cash-management', [CashManagementController::class, 'storeParty'])->name('cash-management.store-party');
        Route::post('cash-management/quick-add', [CashManagementController::class, 'quickAddLiability'])->name('cash-management.quick-add');
        Route::get('cash-management/{cashParty}', [CashManagementController::class, 'show'])->name('cash-management.show');
        Route::post('cash-management/{cashParty}/transactions', [CashManagementController::class, 'storeTransaction'])->name('cash-management.store-transaction');

        // Trash — view and Restore only. Permanently deleting from Trash is
        // an admin-only action (see the group below).
        Route::get('trash', [TrashController::class, 'index'])->name('trash.index');
        Route::post('trash/{type}/restore', [TrashController::class, 'restore'])->name('trash.restore');
        Route::post('trash/{type}/restore-all', [TrashController::class, 'restoreAll'])->name('trash.restore-all');
        // v3.0 FEATURE 2 — Physical Stock Counting (admin/manager; 'create'
        // must stay before the {stockCount} wildcard).
        Route::get('stock-counts', [StockCountController::class, 'index'])->name('stock-counts.index');
        Route::get('stock-counts/create', [StockCountController::class, 'create'])->name('stock-counts.create');
        Route::post('stock-counts', [StockCountController::class, 'store'])->name('stock-counts.store');
        Route::get('stock-counts/{stockCount}', [StockCountController::class, 'show'])->name('stock-counts.show');
        Route::get('stock-counts/{stockCount}/edit', [StockCountController::class, 'edit'])->name('stock-counts.edit');
        Route::patch('stock-counts/{stockCount}', [StockCountController::class, 'update'])->name('stock-counts.update');
        Route::post('stock-counts/{stockCount}/approve', [StockCountController::class, 'approve'])->name('stock-counts.approve');

        // v3.0 FEATURE 3 — Customer Aging (read-only report).
        Route::get('aging', [AgingController::class, 'index'])->name('aging.index');
        Route::get('aging/{customer}', [AgingController::class, 'show'])->name('aging.show');

        // v3.0 FEATURE 4 — Audit Log (read-only).
        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');

        // v3.0 FEATURE 5 — SMS (manual only): reminder button + log/send form.
        Route::post('customers/{customer}/sms-reminder', [CustomerController::class, 'sendReminder'])->name('customers.sms-reminder');
        Route::get('sms-logs', [SmsLogController::class, 'index'])->name('sms-logs.index');
        Route::post('sms-logs', [SmsLogController::class, 'store'])->name('sms-logs.store');

        // v3.0 FEATURE 8 — CSV exports (same gate as the report pages).
        Route::get('reports/export/capital', [ReportExportController::class, 'capital'])->name('reports.export.capital');
        Route::get('reports/export/customers', [ReportExportController::class, 'customers'])->name('reports.export.customers');
        Route::get('reports/export/stock', [ReportExportController::class, 'stock'])->name('reports.export.stock');
        Route::get('reports/export/purchases', [ReportExportController::class, 'purchases'])->name('reports.export.purchases');
        Route::get('reports/export/cash-reconciliation', [ReportExportController::class, 'cashReconciliation'])->name('reports.export.cash-reconciliation');
    });

    // v3.0 FEATURE 6 — Full Accounting (journal / P&L / Balance Sheet).
    Route::middleware('role:admin,manager')->group(function () {
        Route::get('accounting/journal', [AccountingController::class, 'index'])->name('accounting.journal');
        Route::get('accounting/profit-loss', [AccountingController::class, 'profitLoss'])->name('accounting.profit-loss');
        Route::get('accounting/balance-sheet', [AccountingController::class, 'balanceSheet'])->name('accounting.balance-sheet');
    });
    Route::middleware('role:admin')->group(function () {
        Route::get('accounting/opening-balance', [AccountingController::class, 'openingBalance'])->name('accounting.opening-balance');
        Route::post('accounting/opening-balance', [AccountingController::class, 'storeOpeningBalance'])->name('accounting.opening-balance.store');
    });

    // v3.0 FEATURE 9 — online payment initiate: inside 'auth' but OUTSIDE the
    // admin/manager group, so the controller's own "admin/manager OR the
    // order's cashier" check can work. The ledger page itself stays
    // admin/manager-only (unchanged), so a cashier only reaches this
    // button if they can reach the ledger page.
    Route::post('orders/{order}/pay-online', [OrderController::class, 'initiateOnlinePayment'])
        ->name('orders.initiate-online-payment');

    // Admin only — every action that removes data, either by moving it to
    // Trash (soft delete/"disable") or permanently deleting it from Trash.
    // No other role can reach any of these routes.
    Route::middleware('role:admin')->group(function () {
        // Must be registered BEFORE the resource() calls above (they're in
        // a different group already, so ordering across groups is fine),
        // but these literal paths still need to win over the {product}
        // wildcard destroy route below — keep them first in this group too.
        Route::delete('products/force-delete-selected', [ProductController::class, 'forceDeleteSelected'])->name('products.force-delete-selected');
        Route::delete('products/force-delete-all', [ProductController::class, 'forceDeleteAll'])->name('products.force-delete-all');
        Route::delete('products/{product}/force-delete', [ProductController::class, 'forceDeleteOne'])->name('products.force-delete-one');
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
        Route::post('products/destroy-selected', [ProductController::class, 'destroySelected'])->name('products.destroy-selected');
        Route::post('products/destroy-all', [ProductController::class, 'destroyAll'])->name('products.destroy-all');

        Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');
        Route::delete('customers/{customer}/payment/{payment}', [CustomerController::class, 'destroyPayment'])->name('customers.destroy-payment');
        Route::post('customers/destroy-selected', [CustomerController::class, 'destroySelected'])->name('customers.destroy-selected');
        Route::post('customers/destroy-all', [CustomerController::class, 'destroyAll'])->name('customers.destroy-all');

        Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');
        // v2.2 supplier ledger: deleting a ledger entry (admin only, like
        // the customer ledger entry delete just above in spirit).
        Route::delete('suppliers/{supplier}/payments/{payment}', [SupplierController::class, 'destroyPayment'])->name('suppliers.payments.destroy');
        Route::post('suppliers/destroy-selected', [SupplierController::class, 'destroySelected'])->name('suppliers.destroy-selected');
        Route::post('suppliers/destroy-all', [SupplierController::class, 'destroyAll'])->name('suppliers.destroy-all');

        Route::delete('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'destroy'])->name('purchase-orders.destroy');
        Route::post('purchase-orders/destroy-selected', [PurchaseOrderController::class, 'destroySelected'])->name('purchase-orders.destroy-selected');
        Route::post('purchase-orders/destroy-all', [PurchaseOrderController::class, 'destroyAll'])->name('purchase-orders.destroy-all');

        Route::delete('orders/{order}', [OrderController::class, 'destroy'])->name('orders.destroy');
        Route::post('orders/destroy-selected', [OrderController::class, 'destroySelected'])->name('orders.destroy-selected');
        Route::post('orders/destroy-all', [OrderController::class, 'destroyAll'])->name('orders.destroy-all');

        Route::delete('stock-returns/{stockReturn}', [StockReturnController::class, 'destroy'])->name('stock-returns.destroy');
        Route::post('stock-returns/destroy-selected', [StockReturnController::class, 'destroySelected'])->name('stock-returns.destroy-selected');
        Route::post('stock-returns/destroy-all', [StockReturnController::class, 'destroyAll'])->name('stock-returns.destroy-all');

        Route::delete('trash/{type}', [TrashController::class, 'forceDelete'])->name('trash.force-delete');
        Route::delete('trash/{type}/all', [TrashController::class, 'forceDeleteAll'])->name('trash.force-delete-all');

        Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
        Route::post('expenses/destroy-selected', [ExpenseController::class, 'destroySelected'])->name('expenses.destroy-selected');

        Route::delete('cash-management/{cashParty}', [CashManagementController::class, 'destroyParty'])->name('cash-management.destroy-party');
        Route::delete('cash-management/{cashParty}/transactions/{transaction}', [CashManagementController::class, 'destroyTransaction'])->name('cash-management.destroy-transaction');

        Route::resource('users', UserController::class)->except(['show']);
        Route::post('users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');
        Route::post('users/destroy-selected', [UserController::class, 'destroySelected'])->name('users.destroy-selected');
        Route::post('users/destroy-all', [UserController::class, 'destroyAll'])->name('users.destroy-all');

        // v3.0 FEATURE 2 — deleting an OPEN stock count only (approved
        // counts are refused in the controller; items cascade).
        Route::delete('stock-counts/{stockCount}', [StockCountController::class, 'destroy'])->name('stock-counts.destroy');

        Route::get('settings', [SettingController::class, 'edit'])->name('settings.edit');
        Route::put('settings', [SettingController::class, 'update'])->name('settings.update');

        Route::get('backup', [BackupController::class, 'index'])->name('backup.index');
        Route::get('backup/download', [BackupController::class, 'download'])->name('backup.download');
        Route::post('backup/restore', [BackupController::class, 'restore'])->name('backup.restore');
        Route::post('backup/reset', [BackupController::class, 'reset'])->name('backup.reset');
    });
});
