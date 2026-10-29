<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// v3.1.0 (M12): enforce the journal's once-per-event rule in the
// database, not just in AccountingService::alreadyPosted(). A unique
// index on (source_type, source_id) makes a second posting of the same
// event fail at insert time even if two processes race past the
// application-level check; the service catches that duplicate-key
// failure and treats it as "already posted" (see postEntry()).
// MySQL unique indexes allow multiple NULLs, so manual opening-balance
// entries and edit-delta entries — both posted with a NULL source_id
// precisely so they can repeat — are unaffected by this index.
// Pre-existing duplicate (source_type, source_id) pairs, if any exist
// in a live database, will make this migration FAIL: clean them up
// (keep the earliest entry per pair) before running it. The index name
// is explicit so down() can drop it without guessing Laravel's
// generated name.
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('journal_entries')) {
            return;
        }
        if (Schema::hasColumn('journal_entries', 'source_type') && Schema::hasColumn('journal_entries', 'source_id')) {
            // hasIndex is not available on every grammar version used
            // here, so check the index list directly where possible and
            // otherwise let a duplicate-index failure surface loudly
            // rather than silently skipping the constraint.
            $indexes = [];
            try {
                $indexes = array_column(Schema::getIndexes('journal_entries'), 'name');
            } catch (\Throwable $e) {
                $indexes = [];
            }
            if (!in_array('journal_entries_source_unique', $indexes, true)) {
                Schema::table('journal_entries', function (Blueprint $table) {
                    $table->unique(['source_type', 'source_id'], 'journal_entries_source_unique');
                });
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('journal_entries')) {
            return;
        }
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropUnique('journal_entries_source_unique');
        });
    }
};
