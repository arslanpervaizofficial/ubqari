<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ubqari v2 audit fixes — schema changes for the auth / ledger / reporting
 * findings. Run: php artisan migrate
 *
 * IMPORTANT — READ BEFORE MIGRATING: the three unique indexes below
 * (products.barcode, customers.phone, suppliers.phone) will FAIL if
 * duplicate values already exist in those columns. Clean duplicates
 * first (merge the duplicate rows, keeping the one with history), then
 * run this migration. Unique indexes on nullable columns still allow
 * multiple NULLs in MySQL, so blank phones/barcodes are unaffected.
 *
 * Every addition is guarded (hasColumn / hasIndex) so re-running the
 * migration, or running it on a database that already has one of these
 * columns, does not error on the guarded items. The unique-index guards
 * use Schema::hasIndex where the driver supports it; on drivers where
 * that check is unavailable the add is attempted plain and the
 * duplicate warning above applies in full.
 */
return new class extends Migration {
    /** Does the named index exist? False when the check itself is
     *  unsupported, so callers fall back to attempting the change. */
    private function hasIndex(string $table, string $index): bool
    {
        try {
            return Schema::hasIndex($table, $index);
        } catch (\Throwable) {
            return false;
        }
    }

    public function up(): void
    {
        // Split-payment breakdown (H23): cash_amount / bank_amount were
        // computed at completion but never stored, so cash reconciliation
        // could not see the cash half of a split sale.
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'cash_amount')) {
                $table->decimal('cash_amount', 12, 2)->nullable();
            }
            if (!Schema::hasColumn('orders', 'bank_amount')) {
                $table->decimal('bank_amount', 12, 2)->nullable();
            }
        });

        // Purchase-report date basis (H24): purchase_orders had no
        // received-date column, so reports could only filter by created_at.
        // The receive flow sets this (other agent's file).
        Schema::table('purchase_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('purchase_orders', 'received_at')) {
                $table->timestamp('received_at')->nullable();
            }
        });

        // Return-credit reversal (H5): a customer credit payment created
        // by a return must be linkable back to that return so deleting
        // the return can reverse the credit instead of leaving it.
        Schema::table('customer_payments', function (Blueprint $table) {
            if (!Schema::hasColumn('customer_payments', 'stock_return_id')) {
                $table->foreignId('stock_return_id')->nullable()->constrained()->nullOnDelete();
            }
        });

        // Cash parties were the only money records without soft deletes,
        // so deleting a party hard-deleted its whole history (H16).
        foreach (['cash_parties', 'cash_transactions'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (!Schema::hasColumn($tableName, 'deleted_at')) {
                    $table->softDeletes();
                }
            });
        }

        // Forced password change after a database reset (H20).
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'must_change_password')) {
                $table->boolean('must_change_password')->default(false);
            }
        });

        // H28-adjacent uniqueness: barcode is what the till scans (the
        // plain index allowed duplicates, so a scan could pick the wrong
        // product), and phone is what parties are looked up by.
        // See the header warning about pre-existing duplicates.
        if (!$this->hasIndex('products', 'products_barcode_unique')) {
            Schema::table('products', function (Blueprint $table) {
                $table->unique('barcode', 'products_barcode_unique');
            });
        }
        if (!$this->hasIndex('customers', 'customers_phone_unique')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->unique('phone', 'customers_phone_unique');
            });
        }
        if (!$this->hasIndex('suppliers', 'suppliers_phone_unique')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->unique('phone', 'suppliers_phone_unique');
            });
        }
    }

    public function down(): void
    {
        if ($this->hasIndex('suppliers', 'suppliers_phone_unique')) {
            Schema::table('suppliers', function (Blueprint $table) {
                $table->dropUnique('suppliers_phone_unique');
            });
        }
        if ($this->hasIndex('customers', 'customers_phone_unique')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropUnique('customers_phone_unique');
            });
        }
        if ($this->hasIndex('products', 'products_barcode_unique')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropUnique('products_barcode_unique');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'must_change_password')) {
                $table->dropColumn('must_change_password');
            }
        });

        foreach (['cash_transactions', 'cash_parties'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'deleted_at')) {
                    $table->dropSoftDeletes();
                }
            });
        }

        Schema::table('customer_payments', function (Blueprint $table) {
            if (Schema::hasColumn('customer_payments', 'stock_return_id')) {
                $table->dropForeign(['stock_return_id']);
                $table->dropColumn('stock_return_id');
            }
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_orders', 'received_at')) {
                $table->dropColumn('received_at');
            }
        });

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'cash_amount')) {
                $table->dropColumn('cash_amount');
            }
            if (Schema::hasColumn('orders', 'bank_amount')) {
                $table->dropColumn('bank_amount');
            }
        });
    }
};
