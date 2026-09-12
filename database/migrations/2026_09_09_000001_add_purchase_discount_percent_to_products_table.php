<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The default discount % this product's supplier gives us on the
     *  purchase price — e.g. Rate 390 with a 25% discount means we
     *  actually pay 292.50/unit. Stored on the product so it can be
     *  pre-filled into the "Discount %" field next to Cost when adding
     *  this product to a Purchase Order, instead of having to remember/
     *  retype it every time. It's still just a default — each PO line's
     *  discount can be overridden for that specific order. */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('purchase_discount_percent', 5, 2)->default(0)->after('purchase_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('purchase_discount_percent');
        });
    }
};
