<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** Every table the sync contract names, verified against the
     *  v3.1.0 base migrations. Names match the contract exactly, so no
     *  table-name mapping is needed. Phase 1 tables first, then the
     *  Phase 2 additions, in contract order. */
    public const SYNCABLE_TABLES = [
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
    ];

    public function up(): void
    {
        foreach (self::SYNCABLE_TABLES as $table) {
            if (!Schema::hasTable($table)) {
                // Never invent a table: a missing table is skipped and
                // left for the coordinator to report, not created here.
                continue;
            }

            if (!Schema::hasColumn($table, 'client_uuid')) {
                Schema::table($table, function (Blueprint $t) {
                    // 36 chars = UUID text length. Kept short on purpose:
                    // a unique index on a full varchar(255) risks MySQL
                    // key-length trouble; a UUID never needs more.
                    $t->string('client_uuid', 36)->nullable()->after('id');
                });
            }

            // Backfill only rows that still lack one, in chunks, so
            // re-running this migration never rewrites existing UUIDs
            // (and therefore never breaks rows already matched by a
            // desktop client on client_uuid).
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

            // Unique index, added only if it is not already there, so a
            // half-applied earlier run does not fail on re-run.
            $indexNames = collect(Schema::getIndexes($table))->pluck('name')->all();
            if (!in_array($table.'_client_uuid_unique', $indexNames, true)) {
                Schema::table($table, function (Blueprint $t) {
                    $t->unique('client_uuid');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (self::SYNCABLE_TABLES as $table) {
            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'client_uuid')) {
                continue;
            }

            $indexNames = collect(Schema::getIndexes($table))->pluck('name')->all();
            if (in_array($table.'_client_uuid_unique', $indexNames, true)) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropUnique(['client_uuid']);
                });
            }

            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('client_uuid');
            });
        }
    }
};
