<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ubqari POS v3.0 — Feature 6 (Full Accounting): double-entry journal.
 *
 * Two tables only, exactly as specified:
 *  - journal_entries: one balanced entry per business event.
 *  - journal_lines:   the debit/credit lines belonging to an entry.
 *
 * Balance is NOT enforced by the database (no check constraint, so the
 * tables stay portable across MySQL/SQLite); it is enforced in
 * App\Services\AccountingService::postEntry(), which throws and rolls
 * back if sum(debit) != sum(credit). Never insert journal rows by hand
 * outside that service.
 *
 * source_type / source_id identify the business record that caused the
 * entry (e.g. 'order' + order id) so the service can refuse to post the
 * same event twice. Manual opening-balance entries use
 * source_type = 'opening_balance' with a NULL source_id (each form
 * submission is a new, separate entry, so no duplicate guard applies).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->date('entry_date');
            $table->string('reference')->nullable();
            $table->string('description')->nullable();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
            $table->index('entry_date');
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->string('account');
            $table->decimal('debit', 12, 2)->default(0);
            $table->decimal('credit', 12, 2)->default(0);
            $table->timestamps();

            $table->index('account');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
    }
};
