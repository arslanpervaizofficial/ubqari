<?php

namespace App\Observers;

use App\Models\CustomerPayment;
use App\Services\AccountingService;

/**
 * v3.1.0 (M11): CustomerPayment rows now post — see
 * AccountingService::customerPaymentLinesFor() for the legs and the
 * distinguishing rule (stock_return_id separates return credits from
 * cash receipts). type=debit never posts (no honest second leg).
 *
 * v3.1.0 (H12) lifecycle, via AccountingService only:
 *   - created: posts the payment (receipt or return credit).
 *   - updated (amount or type changed): posts ONE delta entry
 *     (customer_payment_edit) — new legs minus old legs, per account —
 *     built from the pre-update values (getOriginal), so repeated
 *     edits each post their own delta.
 *   - deleted (to Trash): posts the flipped legs
 *     (customer_payment_reversal), recomputed from the current row so
 *     an edit followed by a delete still reverses the net amount.
 *   - restored: re-posts the current legs
 *     (customer_payment_restore) when a reversal exists.
 * A second delete/restore cycle posts nothing further (duplicate
 * guard); a permanent (force) delete posts no reversal.
 */
class CustomerPaymentObserver
{
    public function created(CustomerPayment $payment): void
    {
        app(AccountingService::class)->postCustomerPayment($payment);
    }

    public function updated(CustomerPayment $payment): void
    {
        if (!$payment->wasChanged(['amount', 'type'])) {
            return;
        }
        $service = app(AccountingService::class);
        $oldLines = $service->customerPaymentLinesFor(
            (string) $payment->getOriginal('type'),
            $payment->getOriginal('stock_return_id'),
            (float) $payment->getOriginal('amount'),
        );
        $newLines = $service->customerPaymentLinesFor(
            $payment->type,
            $payment->getAttribute('stock_return_id'),
            (float) $payment->amount,
        );
        $service->postEditDelta(
            'customer_payment',
            (int) $payment->id,
            $oldLines,
            $newLines,
            'Customer Payment #' . $payment->id,
            'Payment from customer #' . $payment->customer_id,
            $payment->user_id,
        );
    }

    public function deleted(CustomerPayment $payment): void
    {
        app(AccountingService::class)->reverseCustomerPayment($payment);
    }

    public function restored(CustomerPayment $payment): void
    {
        app(AccountingService::class)->restoreCustomerPayment($payment);
    }
}
