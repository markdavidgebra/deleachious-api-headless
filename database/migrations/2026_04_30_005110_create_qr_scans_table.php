<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qr_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qr_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('scanned_by')
                  ->nullable()
                  ->constrained('admins')
                  ->nullOnDelete();           // which staff scanned it
            $table->foreignId('branch_id')
                  ->nullable()
                  ->constrained('branches')
                  ->nullOnDelete();           // which branch it was scanned at
            $table->string('action');
            // points_earned, reward_redeemed, order_verified, failed
            $table->string('result');         // success, failed, expired, already_used
            $table->integer('points_affected')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qr_scans');
    }
};