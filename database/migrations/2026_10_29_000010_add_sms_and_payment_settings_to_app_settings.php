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
        // v3.1.0 (M14): EACH of the 12 column adds is guarded with
        // hasColumn, exactly like migration 000002 does, so re-running
        // this migration (or running it on a database where some of
        // these columns already exist) no longer crashes on a duplicate
        // column. NOTE: this does nothing for databases where 000010
        // already ran — a migration that has run is never re-run by
        // Laravel, so an already-migrated database keeps whatever
        // columns it already has; the guards only protect fresh or
        // partial runs.
        $columns = [
            'sms_provider' => fn (Blueprint $table) => $table->string('sms_provider')->nullable(),
            'sms_api_url' => fn (Blueprint $table) => $table->string('sms_api_url')->nullable(),
            'sms_api_key' => fn (Blueprint $table) => $table->string('sms_api_key')->nullable(),
            'sms_sender' => fn (Blueprint $table) => $table->string('sms_sender')->nullable(),
            // Default TRUE = test mode is the default: a fresh install
            // (or a shop that has not configured a gateway yet) can
            // never send a real SMS until an admin deliberately turns
            // this off AND fills in a URL and key.
            'sms_test_mode' => fn (Blueprint $table) => $table->boolean('sms_test_mode')->default(true),
            'jazzcash_merchant_id' => fn (Blueprint $table) => $table->string('jazzcash_merchant_id')->nullable(),
            'jazzcash_password' => fn (Blueprint $table) => $table->string('jazzcash_password')->nullable(),
            'jazzcash_integrity_salt' => fn (Blueprint $table) => $table->string('jazzcash_integrity_salt')->nullable(),
            'jazzcash_live_mode' => fn (Blueprint $table) => $table->boolean('jazzcash_live_mode')->default(false),
            'easypaisa_store_id' => fn (Blueprint $table) => $table->string('easypaisa_store_id')->nullable(),
            'easypaisa_hash_key' => fn (Blueprint $table) => $table->string('easypaisa_hash_key')->nullable(),
            'easypaisa_live_mode' => fn (Blueprint $table) => $table->boolean('easypaisa_live_mode')->default(false),
        ];
        foreach ($columns as $column => $add) {
            if (!Schema::hasColumn('app_settings', $column)) {
                Schema::table('app_settings', function (Blueprint $table) use ($add) {
                    $add($table);
                });
            }
        }
    }

    public function down(): void
    {
        $present = array_values(array_filter([
            'sms_provider', 'sms_api_url', 'sms_api_key', 'sms_sender', 'sms_test_mode',
            'jazzcash_merchant_id', 'jazzcash_password', 'jazzcash_integrity_salt', 'jazzcash_live_mode',
            'easypaisa_store_id', 'easypaisa_hash_key', 'easypaisa_live_mode',
        ], fn ($column) => Schema::hasColumn('app_settings', $column)));
        if (count($present) === 0) {
            return;
        }
        Schema::table('app_settings', function (Blueprint $table) use ($present) {
            $table->dropColumn($present);
        });
    }
};
