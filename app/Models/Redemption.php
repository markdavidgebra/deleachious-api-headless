<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Redemption extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'point_item_id',
        'points_used',
        'status',
        'redeemed_at',
    ];

    protected $appends = [
        'reward_id',
    ];

    protected $casts = [
        'redeemed_at' => 'datetime',
        'points_used' => 'integer',
    ];

    public function getRewardIdAttribute()
    {
        return $this->point_item_id;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function pointItem()
    {
        return $this->belongsTo(PointItem::class);
    }

    public function reward()
    {
        return $this->pointItem();
    }

    public function qrCode()
    {
        return $this->morphOne(QrCode::class, 'qrable')->latestOfMany();
    }
}
