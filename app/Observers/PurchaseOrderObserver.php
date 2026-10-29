<?php

namespace App\Observers;

use App\Models\PurchaseOrder;
use App\Services\AccountingService;

/**
 * Posts the purchase journal entry the moment a PO BECOMES received.
 * Same once-only pattern as OrderObserver: the service guard also
 * blocks a duplicate if the status flips again.
 *
 * v3.1.0 (H12) reversals, mirroring OrderObserver exactly:
 *   - received → any other status, or delete: posts the flipped
 *     purchase legs (purchase_order_reversal), read back from the
 *     original entry.
 *   - restore from Trash: re-posts the original legs
 *     (purchase_order_restore) when a reversal exists; otherwise, if
 *     the PO is still received, posts the purchase entry.
 *   - Item/total corrections that keep the status received are NOT
 *     journalled; a second delete/restore cycle and permanent (force)
 *     deletes likewise post nothing — see the journal page note.
 */
class PurchaseOrderObserver
{
    public function updated(PurchaseOrder $purchaseOrder): void
    {
        $service = app(AccountingService::class);
        if (!$purchaseOrder->wasChanged('status')) {
            return;
        }
        if ($purchaseOrder->status === 'received') {
            if ($service->alreadyPosted('purchase_order_reversal', (int) $purchaseOrder->id)) {
                $service->restorePurchaseOrder($purchaseOrder);
            } else {
                $service->postPurchaseOrder($purchaseOrder);
            }
        } elseif ($purchaseOrder->getOriginal('status') === 'received') {
            $service->reversePurchaseOrder($purchaseOrder);
        }
    }

    public function deleted(PurchaseOrder $purchaseOrder): void
    {
        app(AccountingService::class)->reversePurchaseOrder($purchaseOrder);
    }

    public function restored(PurchaseOrder $purchaseOrder): void
    {
        $service = app(AccountingService::class);
        if ($purchaseOrder->status !== 'received') {
            return;
        }
        if ($service->alreadyPosted('purchase_order_reversal', (int) $purchaseOrder->id)) {
            $service->restorePurchaseOrder($purchaseOrder);
        } else {
            $service->postPurchaseOrder($purchaseOrder);
        }
    }
}
