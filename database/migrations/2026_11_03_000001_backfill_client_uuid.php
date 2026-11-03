<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * 2026-11-03 SYNC FIX (backfill half).
     *
     * The creating-hook in AppServiceProvider covers every row made
     * AFTER this fix. This migration covers the rows made BEFORE it:
     * every web-created row that still has a NULL client_uuid gets
     * one now, so server pull (whereNotNull('client_uuid')) can
     * finally deliver it to the desktop.
     *
     * DELIBERATELY does NOT touch updated_at: the query-builder
     * update below writes only client_uuid, so each row keeps its
     * original date. That is what keeps the desktop's day grouping
     * honest — but it also means a normal delta Sync now will NOT
     * fetch these rows, because the desktop cursor has already
     * passed their updated_at. After this migration runs, the
     * desktop MUST do a Full re-sync (Needs Review screen), which
     * resets the pull cursor and re-downloads everything.
     *
     * Idempotent and re-runnable: only NULL rows are filled, and
     * already-filled rows are never rewritten (rewriting them
     * would orphan desktop rows matched on the old uuid).
     */
    public const TABLES = [
        'products',
        'customers',
        'suppliers',
        'purchase_orders',
        'purchase_order_items',
        'orders',
        'order_items',
        'stock_movements',
        'product_batches',
        'stock_returns',
        'expenses',
        'cash_parties',
        'cash_transactions',
        'customer_payments',
        'supplier_payments',
        'stock_counts',
        'stock_count_items',
        'users',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            if (!Schema::hasColumn($table, 'client_uuid')) {
                continue;
            }

            DB::table($table)
                ->whereNull('client_uuid')
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($table) {
                    foreach ($rows as $row) {
                        DB::table($table)
                            ->where('id', $row->id)
                            ->update(['client_uuid' => (string) Str::uuid()]);
                    }
                });
        }
    }

    public function down(): void
    {
        // No-op on purpose: uuids already handed out must never be
        // revoked, or desktop rows matched on them would orphan.
    }
};
