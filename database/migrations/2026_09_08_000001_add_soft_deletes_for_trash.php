<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Adds the Trash feature: "Delete" on these 5 record types now moves the
     *  row to Trash (deleted_at set) instead of removing it outright, and it
     *  can be Restored or Permanently Deleted from there. */
    public function up(): void
    {
        foreach (['customers', 'suppliers', 'users', 'orders', 'purchase_orders'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (['customers', 'suppliers', 'users', 'orders', 'purchase_orders'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropSoftDeletes();
            });
        }
    }
};
