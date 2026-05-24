<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            // Where the main navigation menu lives: "sidebar" (default) or "header".
            $table->string('nav_layout', 16)->default('sidebar')->after('theme_mode');
        });
    }

    public function down(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->dropColumn('nav_layout');
        });
    }
};
