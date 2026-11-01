<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** 2026-11-02 user sync: the users table joins the syncable
     *  tables. Same pattern as migration 2026_10_30_000001 (which
     *  covered the other 17 tables): nullable UUID column, backfill
     *  for rows that lack one, guarded unique index. Re-running
     *  never rewrites existing UUIDs, so rows already matched by a
     *  desktop client on client_uuid keep working. */
    public function up(): void
    {
        if (!Schema::hasTable('users')) {
            return;
        }

        if (!Schema::hasColumn('users', 'client_uuid')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('client_uuid', 36)->nullable()->after('id');
            });
        }

        DB::table('users')
            ->whereNull('client_uuid')
            ->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('users')
                        ->where('id', $row->id)
                        ->update(['client_uuid' => (string) Str::uuid()]);
                }
            });

        $indexNames = collect(Schema::getIndexes('users'))->pluck('name')->all();
        if (!in_array('users_client_uuid_unique', $indexNames, true)) {
            Schema::table('users', function (Blueprint $table) {
                $table->unique('client_uuid');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('users') || !Schema::hasColumn('users', 'client_uuid')) {
            return;
        }

        $indexNames = collect(Schema::getIndexes('users'))->pluck('name')->all();
        if (in_array('users_client_uuid_unique', $indexNames, true)) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['client_uuid']);
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('client_uuid');
        });
    }
};
