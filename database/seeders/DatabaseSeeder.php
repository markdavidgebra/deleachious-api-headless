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
            AdminSeeder::class,               // ← 3rd (admin needs branch)
        ]);
    }
}