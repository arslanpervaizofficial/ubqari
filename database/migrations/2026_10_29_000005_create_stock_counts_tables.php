<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ubqari POS v3.0 — FEATURE 2: Physical Stock Counting.
 *
 * Creates exactly two tables (no other tables):
 *
 *  - stock_counts: one row per physical count. status is 'open' while
 *    counted quantities can still be entered/edited, and 'approved'
 *    once an admin/manager has posted the counted figures to stock.
 *    An approved count is final: it can never be approved again or
 *    edited (enforced in StockCountController).
 *  - stock_count_items: one row per product per count. snapshot_stock
 *    is the system stock (products.stock) frozen at count creation;
 *    counted_stock stays NULL until someone actually counts that
 *    product (blank = not counted yet, never 0 by default); variance
 *    is always counted_stock - snapshot_stock and is recomputed on
 *    every save by StockCountItem's saving event, so it can never
 *    drift from the two figures it is derived from.
 *
 * Deleting an open count removes its items via the cascade below and
 * never touches products.stock (only approve() ever writes stock, and
 * only for counted rows).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->date('count_date');
            // 'open' | 'approved' — kept as a plain string, like the
            // status columns elsewhere in this app (orders, POs).
            $table->string('status')->default('open');
            // The user who created (counted) this count. nullOnDelete:
            // deleting the user must not delete the count history.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            // Deliberately NO cascade: a product that has count history
            // cannot be silently deleted along with that history.
            $table->foreignId('product_id')->constrained();
            $table->decimal('snapshot_stock', 12, 2);
            $table->decimal('counted_stock', 12, 2)->nullable();
            $table->decimal('variance', 12, 2)->nullable();
            $table->timestamps();

            $table->unique(['stock_count_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
    }
};
