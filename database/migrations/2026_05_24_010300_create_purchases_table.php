<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();

            // Public reference number (PUR-YYYYMMDD-XXXXXX).
            $table->string('reference_no', 64)->unique();

            $table->foreignId('wallet_id')
                ->constrained('wallets')
                ->restrictOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            // Branch where the wallet was used. REQUIRED — wallet may only
            // be used inside our own coffee-shop branches.
            $table->foreignId('branch_id')
                ->constrained('branches')
                ->restrictOnDelete();

            // Optional link to the order being paid. Wallet purchases that
            // come from the POS will normally point to an order; ad-hoc QR
            // payments may not have one yet, so it is nullable.
            $table->foreignId('order_id')
                ->nullable()
                ->constrained('orders')
                ->nullOnDelete();

            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('PHP');

            // Pending: dynamic-QR generated, waiting for staff to confirm.
            // Completed: balance debited, ledger entry created.
            // Cancelled: customer/staff cancelled before completion.
            // Refunded: a refund was issued for this purchase.
            $table->enum('status', [
                'pending',
                'completed',
                'cancelled',
                'refunded',
            ])->default('pending');

            // Dynamic single-use QR token (signed payload) used to authorise
            // this specific payment. Generated server-side per transaction.
            $table->string('qr_token', 128)->nullable()->unique();
            $table->timestamp('qr_expires_at')->nullable();

            // Link to the wallet ledger entry once the purchase completes.
            $table->foreignId('wallet_transaction_id')
                ->nullable()
                ->constrained('wallet_transactions')
                ->nullOnDelete();

            // Idempotency key honoured for the pay API.
            $table->string('idempotency_key', 80)->nullable()->unique();

            // Optional notes / staff comment / device info.
            $table->json('metadata')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
