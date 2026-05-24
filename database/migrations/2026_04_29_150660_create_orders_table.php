<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();    // e.g. ORD-20260428-0001
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();                      // null if walk-in customer
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete();
            $table->foreignId('handled_by')
                ->nullable()
                ->constrained('admins')
                ->nullOnDelete();                      // staff who handled it
            $table->string('type')->default('dine_in'); // dine_in, takeout, delivery
            $table->string('status')->default('pending');
            // pending, confirmed, preparing, ready, completed, cancelled
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('discount', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->integer('points_earned')->default(0);
            $table->integer('points_used')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};