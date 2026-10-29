<?php

namespace App\Observers;

use App\Models\SupplierPayment;
use App\Services\AccountingService;

/** Only type=payment posts; the service ignores credit/debit types. */
class SupplierPaymentObserver
{
    public function created(SupplierPayment $payment): void
    {
        app(AccountingService::class)->postSupplierPayment($payment);
    }
}
