<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name');              // Bronze, Silver, Gold, Platinum
            $table->integer('min_points');       // minimum points to reach this tier
            $table->decimal('discount', 5, 2)->default(0); // discount percentage
            $table->string('badge_color')->nullable();     // color for UI
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_tiers');
    }
};