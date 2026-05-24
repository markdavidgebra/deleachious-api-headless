<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class LoyaltyTier extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'min_points',
        'discount',
        'badge_color',
    ];

    protected $casts = [
        'discount'   => 'float',
        'min_points' => 'integer',
    ];

    // A tier has many users
    public function users()
    {
        return $this->hasMany(User::class, 'loyalty_tier_id');
    }
}