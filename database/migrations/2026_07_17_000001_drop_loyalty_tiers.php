<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loyalty_tier_id');
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('loyalty_tier_id');
        });

        Schema::dropIfExists('loyalty_tiers');
    }

    public function down(): void
    {
        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('min_points');
            $table->decimal('discount', 5, 2)->default(0);
            $table->string('badge_color')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('loyalty_tier_id')
                ->nullable()
                ->constrained('loyalty_tiers')
                ->nullOnDelete();
        });

        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('loyalty_tier_id')
                ->nullable()
                ->constrained('loyalty_tiers')
                ->nullOnDelete();
        });
    }
};
