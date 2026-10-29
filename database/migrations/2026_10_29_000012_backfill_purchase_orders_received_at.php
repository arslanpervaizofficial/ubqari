<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// v3.1.0 (H13) backfill ONLY — the receive() code change that writes
// received_at going forward belongs to another worker; this migration
// only repairs rows that are already received but have no date.
// Single UPDATE, nothing else: for purchase_orders rows with
// status='received' and received_at IS NULL, set received_at =
// updated_at, falling back to created_at when updated_at is null.
// Rows that are not received, or already have a received_at, are
// untouched. Guarded by hasColumn so the migration is a no-op on
// databases where received_at does not exist yet.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('purchase_orders') || !Schema::hasColumn('purchase_orders', 'received_at')) {
            return;
        }

        DB::table('purchase_orders')
            ->where('status', 'received')
            ->whereNull('received_at')
            ->update(['received_at' => DB::raw('COALESCE(updated_at, created_at)')]);
    }

    public function down(): void
    {
        // Backfill only: there is no safe inverse (the pre-migration
        // NULLs cannot be told apart from genuinely unknown dates
        // afterwards), so down() intentionally does nothing.
    }
};
