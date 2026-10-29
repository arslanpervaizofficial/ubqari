<?php

namespace App\Observers;

use App\Models\Expense;
use App\Services\AccountingService;

/** Only type=cash_out posts; the service ignores cash_in. */
class ExpenseObserver
{
    public function created(Expense $expense): void
    {
        app(AccountingService::class)->postExpense($expense);
    }
}
