<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** v3.1.0 (L7b) — drop the dead products.active column.
 *
 * products.active was added by 2024_01_09_000001_add_active_to_products_table
 * and superseded by products.is_active (same timestamp, separate migration
 * 2024_01_09_000001_add_is_active_to_products_table). Verified by grep
 * over app/ + resources/ before this migration was written: NO code reads
 * or writes products.active — Product::$fillable and scopeActive() use
 * is_active only, and no controller, view, or query references ->active
 * on a product. The only mention of the column anywhere outside this file
 * is its own creating migration (and the word "active" in an unrelated
 * PurchaseOrderController comment about a product no longer being active).
 *
 * Guarded with hasColumn() so the migration is a no-op on installs where
 * the column is already gone. The duplicate-timestamp pair itself is NOT
 * renamed (L7g): renaming an already-run migration is riskier than
 * leaving it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('products', 'active')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('active');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('products', 'active')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('active')->default(true)->after('max_stock');
            });
        }
    }
};
