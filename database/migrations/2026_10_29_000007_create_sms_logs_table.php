<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Feature 5 (SMS, manual-only) — audit log of every SMS this app sends
 *  (or tries to send, or test-logs). One row per send attempt, written by
 *  SmsService::send(). Nothing here sends anything: sending happens only
 *  when a user clicks a button (ledger "Send SMS reminder", or the SMS
 *  log send form). No other table is created by this migration. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone');
            $table->text('message');
            // sent = gateway accepted it, failed = gateway/transport error,
            // test = test mode was on (or no gateway configured), so no
            // real gateway was ever called.
            $table->string('status');
            $table->text('provider_response')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};
