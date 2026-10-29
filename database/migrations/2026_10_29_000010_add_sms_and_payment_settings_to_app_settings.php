<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// v3.0: settings columns for SMS (Feature 5) and online payments
// (Feature 9). One migration for both fragments: the Feature 5 fragment
// suggested 000008 and the Feature 9 fragment suggested 000010, but
// 000008 is already the journal tables, so all twelve columns live here
// as 000010, after the online_payments table (000009).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->string('sms_provider')->nullable();
            $table->string('sms_api_url')->nullable();
            $table->string('sms_api_key')->nullable();
            $table->string('sms_sender')->nullable();
            // Default TRUE = test mode is the default: a fresh install
            // (or a shop that has not configured a gateway yet) can
            // never send a real SMS until an admin deliberately turns
            // this off AND fills in a URL and key.
            $table->boolean('sms_test_mode')->default(true);
            $table->string('jazzcash_merchant_id')->nullable();
            $table->string('jazzcash_password')->nullable();
            $table->string('jazzcash_integrity_salt')->nullable();
            $table->boolean('jazzcash_live_mode')->default(false);
            $table->string('easypaisa_store_id')->nullable();
            $table->string('easypaisa_hash_key')->nullable();
            $table->boolean('easypaisa_live_mode')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn(['sms_provider', 'sms_api_url', 'sms_api_key', 'sms_sender', 'sms_test_mode',
                'jazzcash_merchant_id', 'jazzcash_password', 'jazzcash_integrity_salt', 'jazzcash_live_mode',
                'easypaisa_store_id', 'easypaisa_hash_key', 'easypaisa_live_mode']);
        });
    }
};
