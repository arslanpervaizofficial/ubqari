<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Set once, the first time an order is completed, and never touched again —
     *  even if the order is later reopened and re-completed via the "load order
     *  to edit" flow. Lets the billing screen know it's editing an existing
     *  sale (so it shows "Update Order" instead of "Complete Order") and lets
     *  Orders history tell a first-time sale apart from an edited one. */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('original_completed_at')->nullable()->after('is_quotation');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('original_completed_at');
        });
    }
};
