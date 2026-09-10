<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_point_settings', function (Blueprint $table) {
            $table->decimal('card_peso_per_point', 10, 2)->default(25);
            $table->decimal('other_peso_per_point', 10, 2)->default(50);
        });

        DB::table('loyalty_point_settings')->update([
            'card_peso_per_point' => 25,
            'other_peso_per_point' => 50,
            'min_purchase' => 0,
        ]);
    }

    public function down(): void
    {
        Schema::table('loyalty_point_settings', function (Blueprint $table) {
            $table->dropColumn(['card_peso_per_point', 'other_peso_per_point']);
        });
    }
};
