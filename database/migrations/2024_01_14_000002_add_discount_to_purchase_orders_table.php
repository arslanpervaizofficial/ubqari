<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->decimal('subtotal', 12, 2)->default(0)->after('status');
            $table->decimal('discount_percent', 5, 2)->default(0)->after('subtotal');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn(['subtotal', 'discount_percent', 'discount_amount']);
        });
    }
};
