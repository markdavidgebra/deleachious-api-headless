<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            BranchSeeder::class,              // ← 1st (branches must exist first)
            LoyaltyPointSettingSeeder::class, // ← 2nd
            RolePermissionSeeder::class,      // ← 3rd (roles before admin assignment)
            AdminSeeder::class,               // ← 4th (admin needs branch + roles)
        ]);
    }
}