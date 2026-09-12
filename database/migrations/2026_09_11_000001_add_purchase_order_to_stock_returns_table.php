<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Same idea as the earlier order_id/order_item_id columns added for
     *  customer returns — a supplier return previously had no way to know
     *  what discount the product was originally PURCHASED at, so it always
     *  returned/credited the full undiscounted cost_price. These columns
     *  let a supplier return be tied back to the exact purchase order /
     *  purchase order item it came from, so its discount carries over
     *  automatically the same way. discount_percent/discount_amount already
     *  exist on this table from the customer-side migration and are reused
     *  here rather than duplicated. */
    public function up(): void
    {
        Schema::table('stock_returns', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->after('order_item_id')->constrained()->nullOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->after('purchase_order_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
            $table->dropConstrainedForeignId('purchase_order_item_id');
        });
    }
};
