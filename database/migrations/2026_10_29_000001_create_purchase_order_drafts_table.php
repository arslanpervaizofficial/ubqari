<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fix 1 — temporary draft storage for the Create Purchase Order form.
 *
 * Run: php artisan migrate
 *
 * This table is separate from `purchase_orders` on purpose, so a draft
 * can never be mistaken for a real order, received, or reported. It
 * needs NO change to the existing purchase_orders status enum
 * (pending / received) and NO change to any report query.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_order_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('discount_percent', 8, 2)->default(0);
            $table->json('items')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_drafts');
    }
};
