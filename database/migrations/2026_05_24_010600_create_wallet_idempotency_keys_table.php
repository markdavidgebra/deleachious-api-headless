<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Stores idempotency keys submitted by the mobile app on POSTs.
        // The (user_id, scope, key) tuple is unique so a retried request
        // with the same key returns the original response instead of
        // creating a duplicate top-up / payment / refund.
        Schema::create('wallet_idempotency_keys', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Logical scope of the key:
            //  - topup, purchase, refund, adjustment
            $table->string('scope', 32);

            // The client-supplied key (Idempotency-Key header).
            $table->string('key', 80);

            // Hash of the request payload — if the same key is reused with
            // a different payload we treat it as a conflict (HTTP 409).
            $table->string('request_hash', 64);

            // HTTP status code + JSON body of the original response so we
            // can replay it byte-for-byte to the retried request.
            $table->unsignedSmallInteger('status_code');
            $table->json('response')->nullable();

            // When this record can be evicted. Best practice is 24h.
            $table->timestamp('expires_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'scope', 'key']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_idempotency_keys');
    }
};
