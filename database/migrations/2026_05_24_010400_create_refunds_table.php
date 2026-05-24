<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();

            $table->string('reference_no', 64)->unique();

            $table->foreignId('wallet_id')
                ->constrained('wallets')
                ->restrictOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            // What is being refunded. Polymorphic so we can refund either a
            // purchase, a top-up or a manual adjustment.
            $table->morphs('refundable');

            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('PHP');

            $table->enum('status', [
                'pending',     // requested by user, awaiting admin review
                'approved',    // approved but not yet executed
                'processing',  // executing (e.g. waiting on PayMongo refund)
                'completed',   // refund executed; wallet credited / card refunded
                'rejected',    // admin rejected the request
                'failed',      // gateway rejected the refund
            ])->default('pending');

            // 'wallet' = credit back to wallet balance (most common)
            // 'gateway' = refund the original card payment via gateway
            // 'cash' = paid back at the counter
            $table->enum('method', ['wallet', 'gateway', 'cash'])->default('wallet');

            // Reason supplied by the requester and decision notes from admin.
            $table->string('reason')->nullable();
            $table->text('admin_notes')->nullable();

            // Who approved/rejected the refund (admin id).
            $table->foreignId('reviewed_by')
                ->nullable()
                ->constrained('admins')
                ->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();

            // Link to the wallet ledger entry created when the refund completes.
            $table->foreignId('wallet_transaction_id')
                ->nullable()
                ->constrained('wallet_transactions')
                ->nullOnDelete();

            // Gateway refund id (if processed via PayMongo refund API).
            $table->string('gateway_refund_id')->nullable();

            $table->json('metadata')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
