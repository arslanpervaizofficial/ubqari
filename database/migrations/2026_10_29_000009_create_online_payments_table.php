<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FEATURE 9 (Ubqari POS v3.0) — Online Payments (JazzCash + Easypaisa).
 *
 * Creates ONLY the online_payments table. No other table is created or
 * altered by this migration. (The payment_* settings columns on
 * app_settings are the coordinator's merge job — see
 * settings-fragment.txt — because AppSetting/SettingController are
 * outside this feature's file list.)
 *
 * A row is created PENDING when a shop user starts an online payment
 * from an order ledger page. It only ever becomes "paid" when a gateway
 * callback passes hash verification in PaymentGatewayService; an
 * unverified callback never records an order payment (see
 * OnlinePaymentController). "failed" rows are kept for audit, never
 * deleted, so a disputed payment can always be traced back to the
 * gateway response that produced it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('online_payments', function (Blueprint $table) {
            $table->id();
            // Same cascade as order_payments: force-deleting an order
            // removes its payment rows (orders are soft-deleted first,
            // so this only fires on a permanent Trash delete).
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // 'jazzcash' or 'easypaisa' — nothing else is accepted
            // (validated in OrderController::initiateOnlinePayment and
            // in PaymentGatewayService::initiate()).
            $table->string('gateway');
            $table->decimal('amount', 12, 2);
            // 'pending' | 'paid' | 'failed'
            $table->string('status');
            // Gateway's own transaction id, stored ONLY after a verified
            // success callback (see OnlinePaymentController).
            $table->string('gateway_txn_id')->nullable();
            // The exact fields sent to the gateway (minus secrets — the
            // JazzCash password is never stored here).
            $table->json('request_payload')->nullable();
            // The exact payload the gateway posted back, stored on both
            // verified and failed callbacks for audit.
            $table->json('callback_payload')->nullable();
            // Who started the payment (the ledger user). nullOnDelete so
            // removing a user never erases the payment trail.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('online_payments');
    }
};
