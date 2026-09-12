<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Extends the Trash feature to Stock Returns — "Delete" on a stock
     *  return now moves it to Trash (deleted_at set) instead of removing it
     *  outright, and it can be Restored or Permanently Deleted from there,
     *  same as Customers/Suppliers/Orders/Purchase Orders already work. */
    public function up(): void
    {
        Schema::table('stock_returns', function (Blueprint $t) {
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('stock_returns', function (Blueprint $t) {
            $t->dropSoftDeletes();
        });
    }
};
