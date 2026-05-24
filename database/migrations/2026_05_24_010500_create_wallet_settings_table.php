<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Single-row settings table mirroring the existing shop_settings
        // pattern. Holds wallet-wide limits and the public T&Cs.
        Schema::create('wallet_settings', function (Blueprint $table) {
            $table->id();

            // Configurable money limits (default: ₱10,000 / ₱5,000 / ₱20,000).
            $table->decimal('max_balance', 12, 2)->default(10000);
            $table->decimal('max_topup', 12, 2)->default(5000);
            $table->decimal('min_topup', 12, 2)->default(50);
            $table->decimal('daily_topup_limit', 12, 2)->default(20000);
            $table->decimal('daily_purchase_limit', 12, 2)->default(20000);
            $table->decimal('max_purchase', 12, 2)->default(10000);

            // QR payment settings.
            $table->unsignedInteger('qr_ttl_seconds')->default(120);

            // Fraud thresholds.
            $table->unsignedSmallInteger('failed_topup_threshold')->default(5);
            $table->unsignedSmallInteger('failed_topup_window_minutes')->default(15);

            // Master switches.
            $table->boolean('topup_enabled')->default(true);
            $table->boolean('purchase_enabled')->default(true);
            $table->boolean('refund_enabled')->default(true);

            // Public-facing T&Cs (markdown). Shown in the mobile app before
            // first top-up. Versioned so audit trail can prove what users
            // accepted at any point in time.
            $table->longText('terms_and_conditions')->nullable();
            $table->string('terms_version', 16)->default('1.0');
            $table->timestamp('terms_updated_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_settings');
    }
};
