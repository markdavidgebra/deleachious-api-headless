<?php

namespace Database\Seeders;

use App\Models\LoyaltyTier;
use Illuminate\Database\Seeder;

class LoyaltyTierSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            [
                'name'        => 'Bronze',
                'min_points'  => 0,
                'discount'    => 0,
                'badge_color' => '#cd7f32',
            ],
            [
                'name'        => 'Silver',
                'min_points'  => 500,
                'discount'    => 5,
                'badge_color' => '#c0c0c0',
            ],
            [
                'name'        => 'Gold',
                'min_points'  => 1000,
                'discount'    => 10,
                'badge_color' => '#ffd700',
            ],
            [
                'name'        => 'Platinum',
                'min_points'  => 2000,
                'discount'    => 15,
                'badge_color' => '#e5e4e2',
            ],
        ];

        foreach ($tiers as $tier) {
            LoyaltyTier::create($tier);
        }
    }
}