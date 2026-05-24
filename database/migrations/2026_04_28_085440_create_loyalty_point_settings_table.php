<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_point_settings', function (Blueprint $table) {
            $table->id();

            // Basic earning rule
            $table->decimal('peso_per_point', 10, 2)->default(10.00);
            // e.g. 10.00 means ₱10 = 1 point

            // Bonus points rules
            $table->boolean('bonus_enabled')->default(false);
            $table->decimal('bonus_multiplier', 5, 2)->default(2.00);
            // e.g. 2.00 means double points
            $table->json('bonus_days')->nullable();
            // e.g. ["Saturday", "Sunday"]
            $table->time('bonus_start_time')->nullable();
            $table->time('bonus_end_time')->nullable();

            // Expiry rules
            $table->boolean('expiry_enabled')->default(false);
            $table->integer('expiry_days')->default(365);
            // e.g. 365 means points expire after 1 year

            // Minimum purchase to earn points
            $table->decimal('min_purchase', 10, 2)->default(0);

            // Maximum points per transaction
            $table->integer('max_points_per_transaction')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_point_settings');
    }
};