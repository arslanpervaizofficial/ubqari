<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            // Null until the PO is received. Lets the received qty differ from
            // the ordered qty (supplier short-shipped, extra freebie, etc.) —
            // stock is bumped by THIS number, not the originally ordered one.
            $table->decimal('received_quantity', 12, 2)->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('received_quantity');
        });
    }
};
