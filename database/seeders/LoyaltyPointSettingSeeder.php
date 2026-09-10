<?php

namespace Database\Seeders;

use App\Models\LoyaltyPointSetting;
use Illuminate\Database\Seeder;

class LoyaltyPointSettingSeeder extends Seeder
{
    public function run(): void
    {
        LoyaltyPointSetting::create([
            'peso_per_point'             => 50.00,   // other tenders: ₱50 = 1 point
            'card_peso_per_point'        => 25.00,   // Daleachious Card: ₱25 = 1 point
            'other_peso_per_point'       => 50.00,
            'bonus_enabled'              => false,
            'bonus_multiplier'           => 2.00,    // double points when bonus is on
            'bonus_days'                 => ['Saturday', 'Sunday'],
            'bonus_start_time'           => null,
            'bonus_end_time'             => null,
            'expiry_enabled'             => false,
            'expiry_days'                => 365,     // 1 year
            'min_purchase'               => 0,
            'max_points_per_transaction' => null,    // no cap by default
        ]);
    }
}