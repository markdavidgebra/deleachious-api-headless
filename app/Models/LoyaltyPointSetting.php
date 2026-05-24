<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LoyaltyPointSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'peso_per_point',
        'bonus_enabled',
        'bonus_multiplier',
        'bonus_days',
        'bonus_start_time',
        'bonus_end_time',
        'expiry_enabled',
        'expiry_days',
        'min_purchase',
        'max_points_per_transaction',
    ];

    protected $casts = [
        'bonus_enabled'  => 'boolean',
        'expiry_enabled' => 'boolean',
        'bonus_days'     => 'array',   // stores days as array e.g. ["Saturday","Sunday"]
        'peso_per_point' => 'float',
        'bonus_multiplier'             => 'float',
        'min_purchase'                 => 'float',
        'max_points_per_transaction'   => 'integer',
        'expiry_days'                  => 'integer',
    ];

    // Always get the one and only settings row
    public static function getSettings(): self
    {
        return self::firstOrCreate([], [
            'peso_per_point'   => 10.00,
            'bonus_enabled'    => false,
            'bonus_multiplier' => 2.00,
            'expiry_enabled'   => false,
            'expiry_days'      => 365,
            'min_purchase'     => 0,
        ]);
    }

    // Calculate points for a given purchase amount
    public function calculatePoints(float $amount): int
    {
        // Check minimum purchase
        if ($amount < $this->min_purchase) {
            return 0;
        }

        // Base points
        $points = (int) floor($amount / $this->peso_per_point);

        // Apply bonus multiplier if enabled
        if ($this->bonus_enabled && $this->isBonusPeriod()) {
            $points = (int) floor($points * $this->bonus_multiplier);
        }

        // Apply max points cap if set
        if ($this->max_points_per_transaction) {
            $points = min($points, $this->max_points_per_transaction);
        }

        return $points;
    }

    // Check if current time is within bonus period
    public function isBonusPeriod(): bool
    {
        $today = now()->format('l'); // e.g. "Saturday"

        // Check bonus days
        if ($this->bonus_days && ! in_array($today, $this->bonus_days)) {
            return false;
        }

        // Check bonus time range
        if ($this->bonus_start_time && $this->bonus_end_time) {
            $now   = now()->format('H:i:s');
            $start = $this->bonus_start_time;
            $end   = $this->bonus_end_time;

            if ($now < $start || $now > $end) {
                return false;
            }
        }

        return true;
    }
}