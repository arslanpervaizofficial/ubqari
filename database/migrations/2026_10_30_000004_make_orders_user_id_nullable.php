<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Sync-enabling change beyond the three contract migrations, and
     *  the only schema change in this patch that alters an existing
     *  column: orders.user_id (the cashier) is NOT NULL in the base
     *  schema, but a desktop client has no server user identity, so a
     *  pushed order can never carry a user_id. Without this, every
     *  pushed order would fail its NOT NULL constraint. The web POS
     *  always sets a cashier, so making the column nullable changes
     *  no web behaviour — it only stops the database from rejecting
     *  desktop-originated orders. Pushed orders store a null cashier
     *  (see SyncController); no user is ever invented for them. */
    public function up(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'user_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Deliberately not reversed: re-imposing NOT NULL would fail
        // on any desktop-pushed order already stored with a null
        // cashier. Restoring the constraint, if ever wanted, needs a
        // data backfill first and is a separate, manual migration.
    }
};
