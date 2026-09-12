<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Lets an "adjustment" stock movement created by a stock return point
     *  back at the return that created it — the same way "purchase"
     *  movements point at their PO via purchase_order_id. Without this,
     *  deleting/restoring a stock return has no reliable way to find (and
     *  undo) exactly the stock movement it made. */
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('stock_return_id')->nullable()->after('purchase_order_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_return_id');
        });
    }
};
