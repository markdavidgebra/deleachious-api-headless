<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference_number')->unique(); // e.g. TXN-20260428-0001
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained()
                  ->nullOnDelete();
            $table->string('payment_method');
            // cash, gcash, maya, card, points
            $table->string('status')->default('pending');
            // pending, paid, failed, refunded
            $table->decimal('amount', 10, 2);
            $table->decimal('change', 10, 2)->default(0); // for cash payments
            $table->string('proof')->nullable();           // payment screenshot
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};