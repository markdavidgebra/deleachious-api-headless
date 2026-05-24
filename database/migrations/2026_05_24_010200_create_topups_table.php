<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topups', function (Blueprint $table) {
            $table->id();

            // Public reference number (TOP-YYYYMMDD-XXXXXX). Unique.
            $table->string('reference_no', 64)->unique();

            $table->foreignId('wallet_id')
                ->constrained('wallets')
                ->restrictOnDelete();

            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            // Channel:
            //  - card  : credit/debit card via PayMongo
            //  - gcash : e-wallet via PayMongo
            //  - cash  : counter top-up at branch (manual, by staff)
            //  - admin : back-office adjustment treated as top-up
            $table->enum('channel', ['card', 'gcash', 'maya', 'cash', 'admin']);

            // Currency + amount the customer paid.
            $table->string('currency', 3)->default('PHP');
            $table->decimal('amount', 12, 2);

            // Lifecycle:
            //  - pending          : waiting for gateway confirmation
            //  - awaiting_webhook : checkout created, webhook pending
            //  - succeeded        : confirmed; wallet credited
            //  - failed           : gateway declined / cancelled
            //  - refunded         : refunded back to the customer
            $table->enum('status', [
                'pending',
                'awaiting_webhook',
                'succeeded',
                'failed',
                'refunded',
            ])->default('pending');

            // Payment-gateway specifics. Never store card numbers, CVV, or
            // any sensitive instrument data — only opaque IDs from the gateway.
            $table->string('gateway')->default('paymongo');
            $table->string('gateway_intent_id')->nullable()->index();
            $table->string('gateway_source_id')->nullable();
            $table->string('gateway_payment_id')->nullable()->index();
            $table->string('checkout_url')->nullable();

            // Branch where a counter top-up happened (cash channel only).
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete();

            // Idempotency key honoured for the create-topup API.
            $table->string('idempotency_key', 80)->nullable()->unique();

            // Link to the resulting wallet ledger entry once succeeded.
            $table->foreignId('wallet_transaction_id')
                ->nullable()
                ->constrained('wallet_transactions')
                ->nullOnDelete();

            // Audit fields.
            $table->json('metadata')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['channel', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topups');
    }
};
