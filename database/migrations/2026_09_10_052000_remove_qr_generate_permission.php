<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $ids = DB::table('permissions')
            ->where('name', 'qr.generate')
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        $exists = DB::table('permissions')
            ->where('name', 'qr.generate')
            ->where('guard_name', 'admin')
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('permissions')->insert([
            'name'       => 'qr.generate',
            'guard_name' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
