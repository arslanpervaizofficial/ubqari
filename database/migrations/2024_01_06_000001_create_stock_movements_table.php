<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained();
            $table->enum('type', ['sale', 'purchase', 'wastage', 'adjustment']);
            $table->decimal('quantity', 12, 2); // negative for deductions
            $table->string('reason')->nullable();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->foreignId('order_id')->nullable()->constrained();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
