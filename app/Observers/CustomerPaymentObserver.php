<?php

namespace App\Observers;

use App\Models\CustomerPayment;
use App\Services\AccountingService;

/**
 * Only type=payment posts; the service ignores credit/debit types.
 * NOTE: the base app's CustomerPayment type column only ever holds
 * credit/debit, so this observer is effectively dormant until a
 * type=payment value exists — that is the spec as written, and it is
 * flagged in NOTES.txt rather than silently reinterpreted.
 */
class CustomerPaymentObserver
{
    public function created(CustomerPayment $payment): void
    {
        app(AccountingService::class)->postCustomerPayment($payment);
    }
}
