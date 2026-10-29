<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ubqari v2.2 — FULL supplier ledger (audit finding H15, completed).
 *
 * Before this migration there was no supplier ledger at all: the only
 * supplier money figure was "received PO total", and Capital Liabilities
 * was CashParty balances only, so anything owed to suppliers was
 * invisible in every report.
 *
 * This migration:
 *  (1) creates supplier_payments (payment / credit / debit rows, soft
 *      deletable, linked to the supplier with NO cascade — a force-delete
 *      of a supplier that still has payment rows is blocked by the
 *      foreign key, so the audit trail cannot be silently erased);
 *  (2) adds suppliers.balance (what WE owe the supplier, decimal 12,2,
 *      default 0), backfilled from received purchase orders.
 *
 * Backfill behaviour: every supplier's balance is set to the sum of its
 * RECEIVED purchase order totals (live, non-trashed POs only — the same
 * set Supplier::duesFigures() counts). No payments exist yet at this
 * point, so paid/credits/debits are all 0 and due == received total,
 * exactly as the old dues page showed. Pending POs are NOT included:
 * an order that has not been received is not owed yet.
 *
 * IMPORTANT: run this migration BEFORE copying the v2.2 controller/model
 * files that read suppliers.balance and supplier_payments, or those
 * pages will error on the missing table/column.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            // Deliberately NO cascade: deleting a supplier with payment
            // history must fail at the database, preserving the trail.
            $table->foreignId('supplier_id')->constrained();
            // payment = cash paid to the supplier (owed goes down).
            // credit  = supplier credit note / discount granted (owed goes down).
            // debit   = extra charge by the supplier (owed goes up).
            $table->string('type');
            $table->decimal('amount', 12, 2);
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('suppliers', function (Blueprint $table) {
            if (!Schema::hasColumn('suppliers', 'balance')) {
                $table->decimal('balance', 12, 2)->default(0);
            }
        });

        // Backfill: balance = received PO totals (live POs only).
        $supplierIds = DB::table('suppliers')->pluck('id');
        foreach ($supplierIds as $supplierId) {
            $received = (float) DB::table('purchase_orders')
                ->where('supplier_id', $supplierId)
                ->where('status', 'received')
                ->whereNull('deleted_at')
                ->sum('total');
            DB::table('suppliers')->where('id', $supplierId)->update(['balance' => round($received, 2)]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');

        Schema::table('suppliers', function (Blueprint $table) {
            if (Schema::hasColumn('suppliers', 'balance')) {
                $table->dropColumn('balance');
            }
        });
    }
};
