<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ubqari POS v3.0 — FEATURE 4 (Audit Log).
 *
 * Creates the audit_logs table: one row per audited change (model
 * created / updated / deleted / restored) and per sign-in / sign-out.
 *
 * Notes:
 * - Only created_at is meaningful for an audit row (a log entry is
 *   never edited), but timestamps() is used, as the spec allows — the
 *   updated_at column exists and is simply never written to again.
 * - user_id is nullable with nullOnDelete: deleting a user must never
 *   erase the audit trail that names them; the row stays with a null
 *   user_id and the view shows a fallback label.
 * - old_values / new_values are JSON. On updates they hold CHANGED
 *   attributes only (see AppServiceProvider), never the whole row.
 * - Indexes match the filters on the audit-log index page (user,
 *   model + id, action, date).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model');
            $table->unsignedBigInteger('model_id')->nullable();
            $table->string('action');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip')->nullable();
            $table->timestamps();

            $table->index('user_id');
            $table->index(['model', 'model_id']);
            $table->index('action');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
