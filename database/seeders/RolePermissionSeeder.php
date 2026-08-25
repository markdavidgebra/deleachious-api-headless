<?php

namespace Database\Seeders;

use App\Models\Admin;
use App\Models\Role;
use App\Support\AdminPermissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $guard = AdminPermissions::GUARD;

        foreach (AdminPermissions::names() as $name) {
            Permission::findOrCreate($name, $guard);
        }

        $system = [
            'developer'   => 'Developer',
            'super_admin' => 'Super Admin',
            'admin'       => 'Admin',
            'staff'       => 'Staff',
            'cashier'     => 'Cashier',
        ];

        foreach ($system as $name => $label) {
            $role = Role::findOrCreate($name, $guard);
            $role->display_name = $label;
            $role->is_system    = true;
            $role->save();
            $role->syncPermissions(AdminPermissions::defaultsFor($name));
        }

        Role::query()
            ->where('guard_name', $guard)
            ->whereNotIn('name', array_keys($system))
            ->with('permissions')
            ->each(function (Role $role) {
                $held = $role->permissions->pluck('name')->all();
                $role->syncPermissions(AdminPermissions::normalize($held));
            });

        Admin::query()->each(function (Admin $admin) {
            if (! $admin->role) {
                return;
            }

            if (! Role::query()->where('name', $admin->role)->where('guard_name', AdminPermissions::GUARD)->exists()) {
                return;
            }

            if (! $admin->hasRole($admin->role)) {
                $admin->syncNamedRole($admin->role);
            }
        });
    }
}
