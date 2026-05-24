<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Redemption extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'reward_id',
        'points_used',
        'status',
        'redeemed_at',
    ];

    protected $casts = [
        'redeemed_at' => 'datetime',
        'points_used' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reward()
    {
        return $this->belongsTo(Reward::class);
    }
}