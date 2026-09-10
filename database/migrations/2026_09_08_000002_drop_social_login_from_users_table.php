<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'provider_id')) {
                $table->dropUnique(['provider', 'provider_id']);
                $table->dropColumn(['provider', 'provider_id']);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('users')) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'provider')) {
                $table->string('provider')->nullable()->after('email');
                $table->string('provider_id')->nullable()->after('provider');
                $table->unique(['provider', 'provider_id']);
            }
        });
    }
};
