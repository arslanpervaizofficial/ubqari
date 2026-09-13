<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Named customers already have a proper credit/debit ledger
     *  (customers.credit_balance + the "payments" table via
     *  Customer::payments()). Walk-in orders had no equivalent — if a
     *  walk-in sale had money still owed on it, there was no way to record
     *  it being paid off later. This gives any order (in practice, used
     *  for walk-in ones — named customers keep using their own ledger) the
     *  same credit/debit history against its own due_amount. */
    public function up(): void
    {
        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['credit', 'debit']);
            $table->decimal('amount', 12, 2);
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payments');
    }
};
