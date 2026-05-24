<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_settings', function (Blueprint $table) {
            $table->id();

            $table->string('shop_name')->default('Daleachious Coffee Shop');
            $table->string('tagline')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->time('opening_time')->nullable();
            $table->time('closing_time')->nullable();
            $table->string('currency', 8)->default('₱');
            $table->string('timezone', 64)->default('Asia/Manila');

            // Path relative to the "public" disk, e.g. "logos/abc123.png"
            $table->string('logo_path')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_settings');
    }
};
