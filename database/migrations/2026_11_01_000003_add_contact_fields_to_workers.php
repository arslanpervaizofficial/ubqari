<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 2026-11-01: workers get full contact details (phone, CNIC, email,
 *  photo). All columns nullable so workers created before this patch
 *  keep working; the form requires phone + CNIC on the next edit.
 *  CNIC and email are unique (a CNIC identifies one person); phone is
 *  deliberately NOT unique — two workers may share a family phone. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            if (!Schema::hasColumn('workers', 'phone')) {
                $table->string('phone')->nullable();
            }
            if (!Schema::hasColumn('workers', 'cnic')) {
                $table->string('cnic')->nullable()->unique();
            }
            if (!Schema::hasColumn('workers', 'email')) {
                $table->string('email')->nullable()->unique();
            }
            if (!Schema::hasColumn('workers', 'image')) {
                $table->string('image')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('workers', function (Blueprint $table) {
            $table->dropUnique(['cnic']);
            $table->dropUnique(['email']);
            $table->dropColumn(['phone', 'cnic', 'email', 'image']);
        });
    }
};
