<?php

namespace App\Observers;

use App\Models\PurchaseOrder;
use App\Services\AccountingService;

/**
 * Posts the purchase journal entry the moment a PO BECOMES received.
 * Same once-only pattern as OrderObserver: the service guard also
 * blocks a duplicate if the status flips again.
 */
class PurchaseOrderObserver
{
    public function updated(PurchaseOrder $purchaseOrder): void
    {
        if ($purchaseOrder->wasChanged('status') && $purchaseOrder->status === 'received') {
            app(AccountingService::class)->postPurchaseOrder($purchaseOrder);
        }
    }
}
