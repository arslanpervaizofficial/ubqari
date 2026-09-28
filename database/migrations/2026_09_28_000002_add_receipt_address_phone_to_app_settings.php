<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Optional lines printed under the shop name on the thermal receipt. */
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->string('print_address')->nullable()->after('print_title');
            $table->string('print_phone')->nullable()->after('print_address');
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropColumn(['print_address', 'print_phone']);
        });
    }
};
