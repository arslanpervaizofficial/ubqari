<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Lets a "purchase" stock movement point back at the PO that created it —
     *  the same way "sale" movements already point at their order via
     *  order_id. Without this, deleting/restoring a Purchase Order has no
     *  reliable way to find (and undo) exactly the stock movements it made. */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
        });
    }
};
