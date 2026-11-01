<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** 2026-11-01 worker fix (correction of 2026_11_01_000001):
     *
     *  Workers are NOT staff users. A worker has no login, no role, and
     *  performs no action — the code exists only to name who is
     *  responsible for a bill (typed at billing time, printed as
     *  "Billed by"). The previous batch wrongly put the code on the
     *  users table (which made a worker look like a login account with
     *  a role). This migration creates the separate workers table and
     *  drops users.code. Any codes typed into the staff form must be
     *  re-entered as workers — they are not carried over, because a
     *  staff code and a worker code are different concepts and the old
     *  values were recorded against the wrong entity. */
    public function up(): void
    {
        if (!Schema::hasTable('workers')) {
            Schema::create('workers', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('code')->unique();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'code')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('workers')) {
            Schema::dropIfExists('workers');
        }
        // Best-effort reversal only: the column comes back empty.
        // Codes typed into the staff form are NOT restored — they
        // belong on workers now, not on users.
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'code')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('code')->nullable()->unique()->after('id');
            });
        }
    }
};
