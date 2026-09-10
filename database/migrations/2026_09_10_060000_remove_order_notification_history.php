<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notifications')) {
            DB::table('notifications')->where('type', 'order_update')->delete();
        }
    }

    public function down(): void
    {
        // Removed notification history cannot be restored.
    }
};
