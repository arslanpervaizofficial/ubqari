<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ubqari v3.0 — FEATURE 1 (Expiry + Batch Tracking).
 *
 * Creates ONLY the product_batches table. One row = one received batch
 * of one product (usually one PO line, but a returned quantity can also
 * land here — see StockReturnController::customerStore()).
 *
 * product.stock stays the authority for sellable quantity: batched
 * remaining figures are a breakdown of stock, not a second stock ledger.
 * Old stock received before this migration has no batch rows, and the
 * sale/return paths treat that remainder as unbatched rather than
 * failing (see POSController::complete() and
 * StockReturnController::customerStore()).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('batch_no');
            $table->date('expiry_date')->nullable();
            $table->decimal('quantity', 12, 2);
            $table->decimal('remaining_quantity', 12, 2);
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_batches');
    }
};
