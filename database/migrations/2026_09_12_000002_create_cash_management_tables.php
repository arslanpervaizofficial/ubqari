<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Personal cash-borrowing tracker, deliberately isolated from every
     *  other part of the system — nothing here touches products, orders,
     *  stock, or customer/supplier balances. It exists purely so the owner
     *  can note "I borrowed X from so-and-so" and "I paid Y back", with a
     *  running per-person balance and a dated history. */
    public function up(): void
    {
        Schema::create('cash_parties', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('note')->nullable();
            // Cached running balance (amount currently owed to this
            // person) — same pattern as customers.credit_balance, kept in
            // sync by CashManagementController rather than recalculated on
            // every page load.
            $table->decimal('balance', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_party_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['borrow', 'repay']);
            $table->decimal('amount', 12, 2);
            $table->date('transaction_date');
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_transactions');
        Schema::dropIfExists('cash_parties');
    }
};
