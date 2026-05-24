<?php

namespace Database\Seeders;

use App\Models\LoyaltyPointSetting;
use Illuminate\Database\Seeder;

class LoyaltyPointSettingSeeder extends Seeder
{
    public function run(): void
    {
        LoyaltyPointSetting::create([
            'peso_per_point'             => 10.00,   // ₱10 = 1 point
            'bonus_enabled'              => false,
            'bonus_multiplier'           => 2.00,    // double points when bonus is on
            'bonus_days'                 => ['Saturday', 'Sunday'],
            'bonus_start_time'           => null,
            'bonus_end_time'             => null,
            'expiry_enabled'             => false,
            'expiry_days'                => 365,     // 1 year
            'min_purchase'               => 50.00,   // minimum ₱50 to earn points
            'max_points_per_transaction' => null,    // no cap by default
        ]);
    }
}