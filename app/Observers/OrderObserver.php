<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\AccountingService;

/**
 * Posts the sale journal entry the moment an order BECOMES completed.
 * wasChanged('status') is the "once only" trigger: later edits to an
 * already-completed order (date change, etc.) do not re-post, and the
 * service's source_type + source_id guard skips a second posting even
 * if the status flips again. Known consequence, by design of the
 * spec: an order reopened for editing (load-order flow) and then
 * re-completed keeps its FIRST journal entry — see NOTES.txt.
 */
class OrderObserver
{
    public function updated(Order $order): void
    {
        if ($order->wasChanged('status') && $order->status === 'completed') {
            app(AccountingService::class)->postSale($order);
        }
    }
}
