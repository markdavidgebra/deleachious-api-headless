<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();

            // Globally unique reference for every wallet movement. Generated
            // server-side (UUID v4 prefixed) and used as the public-facing
            // reference number on receipts, support tickets, etc.
            $table->uuid('uuid')->unique();
            $table->string('reference_no', 64)->unique();

            $table->foreignId('wallet_id')
                ->constrained('wallets')
                ->restrictOnDelete();

            // Ledger primitives. Every peso must be accounted for.
            $table->enum('transaction_type', [
                'topup',
                'purchase',
                'refund',
                'adjustment',
            ]);

            // Signed direction: 'credit' increases balance, 'debit' decreases.
            // Stored alongside transaction_type so reports don't have to map.
            $table->enum('direction', ['credit', 'debit']);

            // Always positive; use direction to know the sign.
            $table->decimal('amount', 12, 2);

            // Snapshot of the wallet before/after this transaction is applied.
            // These are the proof of correctness for the ledger and must
            // never be edited.
            $table->decimal('balance_before', 12, 2);
            $table->decimal('balance_after', 12, 2);

            // Optional ties to context (branch where it happened, who
            // initiated it, and the underlying domain record).
            $table->foreignId('branch_id')
                ->nullable()
                ->constrained('branches')
                ->nullOnDelete();

            // Polymorphic link to topups / purchases / refunds.
            $table->nullableMorphs('source');

            // Lifecycle status of the ledger entry itself.
            //  - pending    : reserved (e.g. card top-up awaiting webhook)
            //  - completed  : balance applied; immutable from here
            //  - failed     : never applied; balance untouched
            //  - reversed   : a separate reversing entry has been recorded
            $table->enum('status', ['pending', 'completed', 'failed', 'reversed'])
                ->default('completed');

            // Free-form metadata payload (idempotency key, gateway IDs,
            // device info, geolocation, etc.). Keep this serialised as JSON.
            $table->json('metadata')->nullable();

            // Human-readable note shown on receipts.
            $table->string('description')->nullable();

            // Who created this entry. Polymorphic so we can record both
            // customers (mobile app) and admins (POS/staff/system).
            $table->nullableMorphs('created_by');

            // Idempotency key supplied by client (Idempotency-Key header).
            // Indexed so we can reject duplicates fast.
            $table->string('idempotency_key', 80)->nullable()->index();

            $table->timestamps();
            // Soft delete only — completed financial records are immutable
            // and must never be hard-deleted.
            $table->softDeletes();

            $table->index(['wallet_id', 'created_at']);
            $table->index(['transaction_type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
