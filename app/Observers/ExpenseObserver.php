<?php

namespace App\Observers;

use App\Models\Expense;
use App\Services\AccountingService;

/**
 * Only type=cash_out posts; the service ignores cash_in.
 *
 * v3.1.0 (H12) lifecycle, via AccountingService only:
 *   - created: posts the expense.
 *   - updated (amount or type changed): posts ONE delta entry
 *     (expense_edit) from the pre-update values (getOriginal).
 *   - deleted (to Trash): posts the flipped legs (expense_reversal),
 *     recomputed from the current row.
 *   - restored: re-posts the current legs (expense_restore) when a
 *     reversal exists.
 * A second delete/restore cycle posts nothing further (duplicate
 * guard); a permanent (force) delete posts no reversal.
 */
class ExpenseObserver
{
    public function created(Expense $expense): void
    {
        app(AccountingService::class)->postExpense($expense);
    }

    public function updated(Expense $expense): void
    {
        if (!$expense->wasChanged(['amount', 'type'])) {
            return;
        }
        $service = app(AccountingService::class);
        $service->postEditDelta(
            'expense',
            (int) $expense->id,
            $service->expenseLinesFor((string) $expense->getOriginal('type'), (float) $expense->getOriginal('amount')),
            $service->expenseLinesFor($expense->type, (float) $expense->amount),
            'Expense #' . $expense->id,
            trim(($expense->category ? $expense->category . ' — ' : '') . ($expense->note ?? '')) ?: 'Expense',
            $expense->user_id,
        );
    }

    public function deleted(Expense $expense): void
    {
        app(AccountingService::class)->reverseExpense($expense);
    }

    public function restored(Expense $expense): void
    {
        app(AccountingService::class)->restoreExpense($expense);
    }
}
