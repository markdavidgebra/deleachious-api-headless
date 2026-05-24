<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            BranchSeeder::class,            // ← 1st (branches must exist first)
            LoyaltyTierSeeder::class,       // ← 2nd
            LoyaltyPointSettingSeeder::class, // ← 3rd
            AdminSeeder::class,             // ← 4th (admin needs branch)
            WalletSettingSeeder::class,     // ← 5th (closed-loop wallet limits + T&Cs)
        ]);
    }
}