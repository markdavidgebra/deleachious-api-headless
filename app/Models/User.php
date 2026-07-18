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

    // Closed-loop prepaid wallet. Backend is the single source of truth
    // for the balance — never trust a client-side cached value.
    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    // Get the user's wallet, creating one on the fly the first time it's
    // accessed. Eloquent will return the existing row on subsequent calls.
    public function getOrCreateWallet(): Wallet
    {
        return Wallet::firstOrCreate(
            ['user_id' => $this->id],
            ['current_balance' => 0, 'currency' => 'PHP', 'status' => 'active']
        );
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
