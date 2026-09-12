<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Customer stock returns previously had no idea what discount (if any)
     *  the item was originally sold at — a return always refunded the full
     *  undiscounted unit_price, overpaying the customer whenever the
     *  original sale had a discount. These columns let a return either
     *  (a) be tied back to the exact order/order item it came from, so its
     *  discount can be carried over automatically, or (b) carry its own
     *  manually-entered discount when the item wasn't looked up from a
     *  past order at all. */
    public function up(): void
    {
        Schema::table('stock_returns', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('supplier_id')->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
            $table->decimal('discount_percent', 5, 2)->default(0)->after('unit_price');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('stock_returns', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
            $table->dropConstrainedForeignId('order_item_id');
            $table->dropColumn(['discount_percent', 'discount_amount']);
        });
    }
};
