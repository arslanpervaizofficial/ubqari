<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Rows the server flags for human review instead of silently
     *  accepting (currently: products whose stock went negative after
     *  a push batch). Surfaced to the desktop in the pull response's
     *  additive `flags` array. A flag is "open" while resolved_at is
     *  null; resolving is a future admin-screen concern, not done by
     *  sync itself. */
    public function up(): void
    {
        Schema::create('sync_flags', function (Blueprint $table) {
            $table->id();
            $table->string('table_name')->index();
            $table->string('client_uuid', 36)->index();
            $table->string('reason')->index();
            $table->json('data')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_flags');
    }
};
