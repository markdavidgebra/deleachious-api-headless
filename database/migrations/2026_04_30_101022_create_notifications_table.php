<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sent_by')
                  ->nullable()
                  ->constrained('admins')
                  ->nullOnDelete();          // which admin sent it
            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();          // null = broadcast to all
            $table->foreignId('loyalty_tier_id')
                  ->nullable()
                  ->constrained('loyalty_tiers')
                  ->nullOnDelete();          // null = all tiers
            $table->string('title');
            $table->text('body');
            $table->string('type');
            // promo, order_update, points, general
            $table->json('data')->nullable(); // extra payload
            $table->string('target');
            // all, specific_user, specific_tier
            $table->integer('sent_count')->default(0);  // how many received
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};