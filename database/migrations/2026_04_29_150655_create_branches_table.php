<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->string('name');                    // e.g. Daleachious Main Branch
            $table->string('code')->unique();          // e.g. DAL-001
            $table->text('address');
            $table->string('city');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->time('opening_time')->nullable();  // e.g. 07:00
            $table->time('closing_time')->nullable();  // e.g. 22:00
            $table->boolean('is_active')->default(true);
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};