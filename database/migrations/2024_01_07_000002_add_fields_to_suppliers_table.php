<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('email')->nullable()->after('phone');
            $table->string('id_card_number')->nullable()->after('address');
            $table->string('current_address')->nullable()->after('id_card_number');
            $table->string('permanent_address')->nullable()->after('current_address');
            $table->string('image')->nullable()->after('permanent_address');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['email', 'id_card_number', 'current_address', 'permanent_address', 'image']);
        });
    }
};
