<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            // Global UI styling controlled from the admin Settings → Style tab.
            // Colors are stored as CSS-friendly strings (hex, rgb(), etc.).
            $table->string('font_family', 64)->default('Inter')->after('timezone');
            $table->string('sidebar_bg', 32)->default('#402218')->after('font_family');
            $table->string('header_bg', 32)->default('#ffffff')->after('sidebar_bg');
            $table->string('content_bg', 32)->default('#f7f7f7')->after('header_bg');
            $table->string('theme_mode', 16)->default('lighter')->after('content_bg');
        });
    }

    public function down(): void
    {
        Schema::table('shop_settings', function (Blueprint $table) {
            $table->dropColumn([
                'font_family',
                'sidebar_bg',
                'header_bg',
                'content_bg',
                'theme_mode',
            ]);
        });
    }
};
