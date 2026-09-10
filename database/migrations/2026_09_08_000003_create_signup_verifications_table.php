<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signup_verifications', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('code');
            $table->string('registration_token')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signup_verifications');
    }
};
