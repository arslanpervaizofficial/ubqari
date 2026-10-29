<?php

namespace App\Observers;

use App\Models\CashTransaction;
use App\Services\AccountingService;

/**
 * Borrow and repay both post; the service ignores any other type.
 *
 * v3.1.0 (H12) lifecycle, via AccountingService only:
 *   - created: posts the transaction.
 *   - updated (amount or type changed): posts ONE delta entry
 *     (cash_transaction_edit) from the pre-update values (getOriginal).
 *   - deleted (to Trash): posts the flipped legs
 *     (cash_transaction_reversal), recomputed from the current row.
 *   - restored: re-posts the current legs (cash_transaction_restore)
 *     when a reversal exists.
 * A second delete/restore cycle posts nothing further (duplicate
 * guard); a permanent (force) delete posts no reversal.
 */
class CashTransactionObserver
{
    public function created(CashTransaction $transaction): void
    {
        app(AccountingService::class)->postCashTransaction($transaction);
    }

    public function updated(CashTransaction $transaction): void
    {
        if (!$transaction->wasChanged(['amount', 'type'])) {
            return;
        }
        $service = app(AccountingService::class);
        $service->postEditDelta(
            'cash_transaction',
            (int) $transaction->id,
            $service->cashTransactionLinesFor((string) $transaction->getOriginal('type'), (float) $transaction->getOriginal('amount')),
            $service->cashTransactionLinesFor($transaction->type, (float) $transaction->amount),
            'Cash Transaction #' . $transaction->id,
            'Cash transaction #' . $transaction->id,
            $transaction->user_id,
        );
    }

    public function deleted(CashTransaction $transaction): void
    {
        app(AccountingService::class)->reverseCashTransaction($transaction);
    }

    public function restored(CashTransaction $transaction): void
    {
        app(AccountingService::class)->restoreCashTransaction($transaction);
    }
}
