<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rewards') && ! Schema::hasTable('point_items')) {
            Schema::rename('rewards', 'point_items');
        }

        if (Schema::hasTable('redemptions') && Schema::hasColumn('redemptions', 'reward_id')) {
            Schema::table('redemptions', function (Blueprint $table) {
                $table->dropForeign(['reward_id']);
            });

            Schema::table('redemptions', function (Blueprint $table) {
                $table->renameColumn('reward_id', 'point_item_id');
            });

            Schema::table('redemptions', function (Blueprint $table) {
                $table->foreign('point_item_id')
                    ->references('id')
                    ->on('point_items')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')
                ->where('name', 'loyalty.rewards')
                ->update(['name' => 'loyalty.points']);
        }

        if (Schema::hasTable('loyalty_points')) {
            DB::table('loyalty_points')
                ->where('reference_type', 'App\\Models\\Reward')
                ->update(['reference_type' => 'App\\Models\\PointItem']);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('loyalty_points')) {
            DB::table('loyalty_points')
                ->where('reference_type', 'App\\Models\\PointItem')
                ->update(['reference_type' => 'App\\Models\\Reward']);
        }

        if (Schema::hasTable('permissions')) {
            DB::table('permissions')
                ->where('name', 'loyalty.points')
                ->update(['name' => 'loyalty.rewards']);
        }

        if (Schema::hasTable('redemptions') && Schema::hasColumn('redemptions', 'point_item_id')) {
            Schema::table('redemptions', function (Blueprint $table) {
                $table->dropForeign(['point_item_id']);
            });

            Schema::table('redemptions', function (Blueprint $table) {
                $table->renameColumn('point_item_id', 'reward_id');
            });

            Schema::table('redemptions', function (Blueprint $table) {
                $table->foreign('reward_id')
                    ->references('id')
                    ->on('point_items')
                    ->cascadeOnDelete();
            });
        }

        if (Schema::hasTable('point_items') && ! Schema::hasTable('rewards')) {
            Schema::rename('point_items', 'rewards');
        }

        if (Schema::hasTable('redemptions') && Schema::hasColumn('redemptions', 'reward_id')) {
            Schema::table('redemptions', function (Blueprint $table) {
                $table->dropForeign(['reward_id']);
            });

            Schema::table('redemptions', function (Blueprint $table) {
                $table->foreign('reward_id')
                    ->references('id')
                    ->on('rewards')
                    ->cascadeOnDelete();
            });
        }
    }
};
