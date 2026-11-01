<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 2026-11-01 staff/party batch: party codes for customers, staff
     *  codes for users, and worker attribution on orders.
     *
     *  - customers.party_code: system-assigned, format P-0001 (4-digit
     *    zero-padded, grows past 9999 naturally). The server is the
     *    authority: a pushed code is kept when unused and reassigned
     *    when taken — that rule lives in Customer::saving(), not here,
     *    so it covers web creates, sync pushes and this backfill alike.
     *  - users.code: nullable unique staff code, typed at billing time
     *    to attribute an order to a worker (see POSController::complete).
     *  - orders.worker_code / worker_name: denormalized text, so the
     *    bill keeps the worker's name even if the user is later renamed.
     *
     *  Backfill: every customer with a NULL party_code gets the next
     *  free P-number in id order, continuing past any codes already
     *  present (none on a fresh run — the column is new — but the
     *  max-suffix scan makes a partial re-run safe). */
    public function up(): void
    {
        if (Schema::hasTable('customers') && !Schema::hasColumn('customers', 'party_code')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->string('party_code')->nullable()->unique()->after('id');
            });
        }
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'code')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('code')->nullable()->unique()->after('id');
            });
        }
        if (Schema::hasTable('orders')) {
            if (!Schema::hasColumn('orders', 'worker_code')) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->string('worker_code')->nullable()->after('user_id');
                });
            }
            if (!Schema::hasColumn('orders', 'worker_name')) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->string('worker_name')->nullable()->after('worker_code');
                });
            }
        }

        if (Schema::hasTable('customers') && Schema::hasColumn('customers', 'party_code')) {
            $max = 0;
            foreach (DB::table('customers')->whereNotNull('party_code')->pluck('party_code') as $code) {
                if (preg_match('/^P-(\d+)$/', (string) $code, $m)) {
                    $max = max($max, (int) $m[1]);
                }
            }
            $next = $max + 1;
            $ids = DB::table('customers')->whereNull('party_code')->orderBy('id')->pluck('id');
            foreach ($ids as $id) {
                DB::table('customers')->where('id', $id)->update(['party_code' => sprintf('P-%04d', $next++)]);
            }
        }
    }

    public function down(): void
    {
        // The backfill above is deliberately NOT reversible: dropping
        // the columns discards the assigned codes, and re-running up()
        // would hand out fresh numbers in id order instead of restoring
        // the old ones. Any code already printed on a bill stays valid
        // only as printed text (orders.worker_name is denormalized).
        if (Schema::hasTable('orders')) {
            if (Schema::hasColumn('orders', 'worker_name')) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->dropColumn('worker_name');
                });
            }
            if (Schema::hasColumn('orders', 'worker_code')) {
                Schema::table('orders', function (Blueprint $table) {
                    $table->dropColumn('worker_code');
                });
            }
        }
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'code')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('code');
            });
        }
        if (Schema::hasTable('customers') && Schema::hasColumn('customers', 'party_code')) {
            Schema::table('customers', function (Blueprint $table) {
                $table->dropColumn('party_code');
            });
        }
    }
};
