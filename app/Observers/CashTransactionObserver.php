<?php

namespace App\Observers;

use App\Models\CashTransaction;
use App\Services\AccountingService;

/** Borrow and repay both post; the service ignores any other type. */
class CashTransactionObserver
{
    public function created(CashTransaction $transaction): void
    {
        app(AccountingService::class)->postCashTransaction($transaction);
    }
}
