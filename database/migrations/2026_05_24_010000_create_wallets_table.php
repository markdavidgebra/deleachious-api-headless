<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();

            // Each user has exactly one wallet
            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();

            // Authoritative balance — backend is single source of truth.
            // Stored as decimal(12,2) to safely handle ₱10M-scale balances.
            $table->decimal('current_balance', 12, 2)->default(0);

            // Closed-loop wallet always uses PHP. Kept here so the model is
            // explicit about currency, never inferred from the client.
            $table->string('currency', 3)->default('PHP');

            // Lifecycle states for fraud / compliance:
            //  - active     : normal operation
            //  - frozen     : blocked from new debits/credits, balance preserved
            //  - suspended  : suspected fraud; admin review required
            //  - closed     : permanently closed (cannot be reopened)
            $table->enum('status', ['active', 'frozen', 'suspended', 'closed'])
                ->default('active');

            // Soft hint for last activity — useful for fraud/inactivity logic.
            $table->timestamp('last_activity_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
