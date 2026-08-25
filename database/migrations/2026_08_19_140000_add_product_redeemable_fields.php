<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_redeemable')->default(false)->after('is_featured');
            $table->unsignedInteger('points_required')->nullable()->after('is_redeemable');
        });

        Schema::table('rewards', function (Blueprint $table) {
            $table->foreignId('product_id')
                ->nullable()
                ->after('id')
                ->constrained('products')
                ->nullOnDelete();
            $table->unique('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('rewards', function (Blueprint $table) {
            $table->dropUnique(['product_id']);
            $table->dropConstrainedForeignId('product_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_redeemable', 'points_required']);
        });
    }
};
