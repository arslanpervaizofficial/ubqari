<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time data repair. Before this, a customer paying MORE than an order's
 * total had the extra thrown away (due_amount was clamped to 0), so their
 * credit_balance never showed the overpayment as an advance.
 *
 *  1. orders.due_amount for named-customer completed orders = total - paid (signed)
 *  2. customers.credit_balance = order (total - paid) + debits - credits
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("UPDATE orders SET due_amount = ROUND(total - paid_amount, 2)
                       WHERE customer_id IS NOT NULL AND status = 'completed'");

        foreach (DB::table('customers')->pluck('id') as $id) {
            $orderDue = (float) DB::table('orders')->where('customer_id', $id)
                ->where('status', 'completed')->whereNull('deleted_at')
                ->selectRaw('COALESCE(SUM(total - paid_amount), 0) as d')->value('d');
            $pay = fn ($type) => (float) DB::table('customer_payments')->where('customer_id', $id)
                ->where('type', $type)->whereNull('deleted_at')->sum('amount');

            DB::table('customers')->where('id', $id)->update([
                'credit_balance' => round($orderDue + $pay('debit') - $pay('credit'), 2),
            ]);
        }
    }

    public function down(): void
    {
        // Data repair only — nothing to undo.
    }
};
