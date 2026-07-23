<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar_path',
        'points',
        'fcm_token',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'avatar_path',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'points' => 'integer',
    ];

    // Expose a ready-to-use storage path on every JSON response
    protected $appends = ['avatar_url'];

    public function getAvatarUrlAttribute(): ?string
    {
        if (! $this->avatar_path) {
            return null;
        }

        return '/storage/' . ltrim($this->avatar_path, '/');
    }

    public function loyaltyPoints()
    {
        return $this->hasMany(LoyaltyPoint::class);
    }

    public function redemptions()
    {
        return $this->hasMany(Redemption::class);
    }

    // QR Code relationship
    public function qrCode()
    {
        return $this->morphOne(QrCode::class, 'qrable');
    }
}
