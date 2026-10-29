<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** v3.1.0 (H6) — backstop for the online-payment callback race.
 *
 * A unique index on online_payments.gateway_txn_id, so the same
 * gateway transaction can never be recorded against two payment
 * rows — even if two callbacks slip past the row lock (e.g. a
 * replayed gateway transaction id presented against a second
 * initiated payment). The locked re-check in
 * OnlinePaymentController::handleCallback() is the primary guard;
 * this index is the database-level second line: a duplicate insert
 * fails the transaction instead of double-crediting.
 *
 * MySQL unique indexes allow multiple NULLs, so rows that have
 * never completed a callback (gateway_txn_id NULL: pending/failed
 * rows, and paid rows whose gateway sent no separate id — though
 * callbackTransactionId() falls back to the shop reference, so paid
 * rows should normally carry a value) are unaffected.
 *
 * DEPLOY NOTE: if duplicate non-NULL gateway_txn_id values already
 * exist in the table (e.g. from a pre-fix double record), this
 * migration FAILS until those duplicates are reconciled by hand.
 * Check first: SELECT gateway_txn_id, COUNT(*) FROM online_payments
 * WHERE gateway_txn_id IS NOT NULL GROUP BY gateway_txn_id HAVING
 * COUNT(*) > 1;
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_payments', function (Blueprint $table) {
            $table->unique('gateway_txn_id');
        });
    }

    public function down(): void
    {
        Schema::table('online_payments', function (Blueprint $table) {
            $table->dropUnique('online_payments_gateway_txn_id_unique');
        });
    }
};
