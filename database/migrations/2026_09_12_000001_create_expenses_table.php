<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Two kinds of expense:
     *  - cash_in: fresh money the owner puts INTO the business specifically
     *    to cover this expense. Counts toward Total Investment (money ever
     *    put in) AND toward Total Expenses (money spent) — the two cancel
     *    out in the net figure, which is correct: it was brought in and
     *    spent in the same breath, so the business's available balance
     *    doesn't move, but the lifetime investment total does.
     *  - cash_out: the expense is paid using cash the business already
     *    has. Only counts toward Total Expenses, so it pulls the net
     *    (Investment − Expenses) figure down — money that was already
     *    available is now spent. */
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['cash_in', 'cash_out']);
            $table->decimal('amount', 12, 2);
            $table->string('category')->nullable();
            $table->text('note')->nullable();
            $table->date('expense_date');
            $table->foreignId('user_id')->nullable()->constrained();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
