<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')
                  ->nullable()
                  ->constrained('admins')
                  ->nullOnDelete();          // who did the action
            $table->foreignId('branch_id')
                  ->nullable()
                  ->constrained('branches')
                  ->nullOnDelete();          // which branch
            $table->string('action');
            // created, updated, deleted, login, logout,
            // approved, rejected, adjusted, sent
            $table->string('module');
            // product, category, order, member, staff,
            // loyalty, reward, notification, branch, qr
            $table->string('description');   // human readable summary
            $table->nullableMorphs('auditable'); // links to any model
            $table->json('old_values')->nullable(); // before change
            $table->json('new_values')->nullable(); // after change
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};