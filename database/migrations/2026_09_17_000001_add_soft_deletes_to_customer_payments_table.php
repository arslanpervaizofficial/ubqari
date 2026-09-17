<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Extends the Trash feature to a customer ledger's manual Credit/Debit
     *  entries — editing or deleting one now reverses its effect on the
     *  customer's credit_balance and moves it to Trash (deleted_at set)
     *  instead of just disappearing, same as Customers/Orders/Purchase
     *  Orders/Stock Returns already work. */
    public function up(): void
    {
        Schema::table('customer_payments', function (Blueprint $t) {
            $t->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('customer_payments', function (Blueprint $t) {
            $t->dropSoftDeletes();
        });
    }
};
