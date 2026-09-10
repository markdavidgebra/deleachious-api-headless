<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const NAMES = [
        'redemptions',
        'redemptions.view',
        'redemptions.review',
    ];

    public function up(): void
    {
        $ids = DB::table('permissions')
            ->whereIn('name', self::NAMES)
            ->where('guard_name', 'admin')
            ->pluck('id');

        if ($ids->isNotEmpty()) {
            DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (self::NAMES as $name) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name, 'guard_name' => 'admin'],
                ['created_at' => now(), 'updated_at' => now()],
            );
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
