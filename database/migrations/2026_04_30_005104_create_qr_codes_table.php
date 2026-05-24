<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();       // unique QR string
            $table->string('type');                 // user, order
            $table->nullableMorphs('qrable');       // links to User or Order
            $table->string('purpose');
            // user_loyalty, order_pickup, reward_redemption
            $table->boolean('is_active')->default(true);
            $table->integer('scan_count')->default(0);
            $table->integer('max_scans')->nullable(); // null = unlimited
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_codes');
    }
};