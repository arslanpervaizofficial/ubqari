<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_returns', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['from_customer', 'to_supplier']);
            $table->foreignId('product_id')->constrained();
            $table->foreignId('customer_id')->nullable()->constrained();
            $table->foreignId('supplier_id')->nullable()->constrained();
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 12, 2); // sale price for customer returns, cost price for supplier returns
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_returns');
    }
};
