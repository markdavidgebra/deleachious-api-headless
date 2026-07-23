<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Switch app checkout from closed-loop wallet to PayMongo order payments,
 * and drop wallet domain tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('transactions', 'gateway')) {
                $table->string('gateway')->nullable()->after('payment_method');
            }
            if (! Schema::hasColumn('transactions', 'gateway_checkout_id')) {
                $table->string('gateway_checkout_id')->nullable()->after('gateway');
            }
            if (! Schema::hasColumn('transactions', 'gateway_payment_id')) {
                $table->string('gateway_payment_id')->nullable()->after('gateway_checkout_id');
            }
            if (! Schema::hasColumn('transactions', 'checkout_url')) {
                $table->text('checkout_url')->nullable()->after('gateway_payment_id');
            }
        });

        // Dependents first.
        Schema::dropIfExists('wallet_idempotency_keys');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('topups');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('wallets');
        Schema::dropIfExists('wallet_settings');
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            foreach (['checkout_url', 'gateway_payment_id', 'gateway_checkout_id', 'gateway'] as $col) {
                if (Schema::hasColumn('transactions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        // Wallet tables are not recreated here — restore from backups if needed.
    }
};
