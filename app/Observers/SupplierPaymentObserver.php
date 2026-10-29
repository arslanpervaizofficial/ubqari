<?php

namespace App\Observers;

use App\Models\SupplierPayment;
use App\Services\AccountingService;

/**
 * Only type=payment posts; the service ignores credit/debit types.
 *
 * v3.1.0 (H12) lifecycle, via AccountingService only:
 *   - created: posts the payment.
 *   - updated (amount or type changed): posts ONE delta entry
 *     (supplier_payment_edit) from the pre-update values (getOriginal).
 *   - deleted (to Trash): posts the flipped legs
 *     (supplier_payment_reversal), recomputed from the current row.
 *   - restored: re-posts the current legs (supplier_payment_restore)
 *     when a reversal exists.
 * A second delete/restore cycle posts nothing further (duplicate
 * guard); a permanent (force) delete posts no reversal.
 */
class SupplierPaymentObserver
{
    public function created(SupplierPayment $payment): void
    {
        app(AccountingService::class)->postSupplierPayment($payment);
    }

    public function updated(SupplierPayment $payment): void
    {
        if (!$payment->wasChanged(['amount', 'type'])) {
            return;
        }
        $service = app(AccountingService::class);
        $service->postEditDelta(
            'supplier_payment',
            (int) $payment->id,
            $service->supplierPaymentLinesFor((string) $payment->getOriginal('type'), (float) $payment->getOriginal('amount')),
            $service->supplierPaymentLinesFor($payment->type, (float) $payment->amount),
            'Supplier Payment #' . $payment->id,
            'Payment to supplier #' . $payment->supplier_id,
            $payment->user_id,
        );
    }

    public function deleted(SupplierPayment $payment): void
    {
        app(AccountingService::class)->reverseSupplierPayment($payment);
    }

    public function restored(SupplierPayment $payment): void
    {
        app(AccountingService::class)->restoreSupplierPayment($payment);
    }
}
