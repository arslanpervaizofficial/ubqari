<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\AccountingService;

/**
 * Posts the sale journal entry the moment an order BECOMES completed.
 * wasChanged('status') is the "once only" trigger: later edits to an
 * already-completed order (date change, etc.) do not re-post, and the
 * service's source_type + source_id guard skips a second posting even
 * if the status flips again.
 *
 * v3.1.0 (H12) reversals, via AccountingService only:
 *   - completed → any other status: posts the flipped sale legs
 *     (order_reversal), read back from the original entry so a later
 *     product-cost edit cannot make the reversal drift from the sale.
 *   - delete (soft delete to Trash): same reversal, when the order was
 *     ever posted. A delete of a never-completed order posts nothing.
 *   - restore from Trash: re-posts the original legs
 *     (order_restore) when a reversal exists; if the order was
 *     restored while still completed and was never posted (e.g. it was
 *     deleted before completion was ever journalled), posts the sale.
 *   - Edits that keep the status completed (item/total corrections)
 *     are NOT journalled — see the unposted list on the journal page.
 *   - A second delete/restore cycle posts nothing further (the
 *     duplicate guard); a permanent (force) delete from Trash fires
 *     forceDeleted, not deleted, so it posts no reversal either.
 */
class OrderObserver
{
    public function updated(Order $order): void
    {
        $service = app(AccountingService::class);
        if (!$order->wasChanged('status')) {
            return;
        }
        if ($order->status === 'completed') {
            if ($service->alreadyPosted('order_reversal', (int) $order->id)) {
                $service->restoreSale($order);
            } else {
                $service->postSale($order);
            }
        } elseif ($order->getOriginal('status') === 'completed') {
            $service->reverseSale($order);
        }
    }

    public function deleted(Order $order): void
    {
        app(AccountingService::class)->reverseSale($order);
    }

    public function restored(Order $order): void
    {
        $service = app(AccountingService::class);
        if ($order->status !== 'completed') {
            return;
        }
        if ($service->alreadyPosted('order_reversal', (int) $order->id)) {
            $service->restoreSale($order);
        } else {
            $service->postSale($order);
        }
    }
}
