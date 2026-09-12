<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A single-row settings table (see App\Models\AppSetting::current()).
     *  menu_title is what shows in the sidebar / mobile header / browser
     *  tab; print_title is what shows on the printed invoice — kept as two
     *  separate fields since a shop's official/legal name on a receipt is
     *  often not the same short name they want branding the software UI. */
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('menu_title')->default('Ubqari POS');
            $table->string('print_title')->default('Ubqari POS');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
