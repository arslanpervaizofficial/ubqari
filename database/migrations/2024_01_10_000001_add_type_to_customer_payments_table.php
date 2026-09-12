<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_payments', function (Blueprint $table) {
            // credit = reduces what the customer owes (payment received, or a return)
            // debit  = increases what the customer owes (manual charge/adjustment)
            $table->enum('type', ['credit', 'debit'])->default('credit')->after('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('customer_payments', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
