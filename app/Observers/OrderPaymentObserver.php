<?php

namespace App\Observers;

use App\Models\OrderPayment;
use App\Services\AccountingService;

/**
 * v3.1.0 (H12) — NEW observer: collects the receivable a completed
 * sale left open. A CREDIT recorded against an already-COMPLETED
 * order posts Dr Cash, Cr Receivables (order_payment). This covers
 * both the order-ledger payment form and verified online-payment
 * callbacks, which both create OrderPayment credits for completed
 * orders.
 *
 * Deliberately NOT posted:
 *   - credits on in_progress orders: the sale entry at completion
 *     already includes the order's paid split, so posting them here
 *     would double-count the cash. (POS completion creates its
 *     ledger rows BEFORE flipping the order to completed, so those
 *     rows are correctly skipped by the status check in the service.)
 *   - debit ledger rows: a correction/charge with no cash movement;
 *     posting Cr Cash would invent cash out.
 *   - edits/deletes of OrderPayment rows: the model has no
 *     SoftDeletes and no delete/edit UI path was found, so no
 *     lifecycle events are wired for them; if one is ever deleted
 *     directly, no journal reversal is posted (see the journal note).
 * Note: an OrderPayment row carries no cash/bank split, so gateway
 * settlements post to Cash here, not Bank.
 */
class OrderPaymentObserver
{
    public function created(OrderPayment $payment): void
    {
        app(AccountingService::class)->postOrderPayment($payment);
    }
}
