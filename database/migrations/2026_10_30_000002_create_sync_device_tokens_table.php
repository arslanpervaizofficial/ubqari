<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Per-device sync tokens. Only the hash is ever stored — the
     *  token itself is printed once by sync:issue-token and then
     *  cannot be recovered from the database. See
     *  SyncDeviceTokenAuth middleware for the lookup. */
    public function up(): void
    {
        Schema::create('sync_device_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('device_id')->index();
            $table->string('name')->nullable();
            $table->string('token_hash')->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_device_tokens');
    }
};
