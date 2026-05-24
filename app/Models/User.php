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
        'points',
        'loyalty_tier_id',
        'fcm_token',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'points' => 'integer',
    ];

    // Always include the loyalty tier on the user payload so the mobile app
    // can render tier name/colour/discount without an extra round trip.
    protected $with = ['loyaltyTier'];

    // Computed fields exposed on JSON responses
    protected $appends = ['next_tier', 'points_to_next_tier', 'progress_to_next_tier'];

    // Relationships
    public function loyaltyTier()
    {
        return $this->belongsTo(LoyaltyTier::class);
    }

    // ── Computed Accessors ───────────────────────────────────────────
    // Resolve the next loyalty tier (smallest min_points strictly greater
    // than the user's current points). Returns null when the user is at
    // the top tier already.
    public function getNextTierAttribute(): ?LoyaltyTier
    {
        return LoyaltyTier::where('min_points', '>', (int) $this->points)
            ->orderBy('min_points')
            ->first();
    }

    // Number of points still needed to reach the next tier (0 at top tier).
    public function getPointsToNextTierAttribute(): int
    {
        $next = $this->next_tier;
        if (! $next) {
            return 0;
        }

        return max(0, $next->min_points - (int) $this->points);
    }

    // Percentage progress from the current tier threshold towards the next.
    // Capped at 100. Returns 100 when there is no next tier.
    public function getProgressToNextTierAttribute(): float
    {
        $next    = $this->next_tier;
        $current = $this->loyaltyTier;

        if (! $next) {
            return 100.0;
        }

        $base  = $current ? (int) $current->min_points : 0;
        $span  = max(1, $next->min_points - $base);
        $into  = max(0, (int) $this->points - $base);

        return round(min(100, ($into / $span) * 100), 2);
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

    // Auto update tier based on points
    public function updateTier()
    {
        $tier = LoyaltyTier::where('min_points', '<=', $this->points)
            ->orderByDesc('min_points')
            ->first();

        if ($tier) {
            $this->update(['loyalty_tier_id' => $tier->id]);
        }
    }
}