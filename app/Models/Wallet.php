<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Wallet extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'current_balance',
        'currency',
        'status',
        'last_activity_at',
    ];

    protected $casts = [
        'current_balance'  => 'decimal:2',
        'last_activity_at' => 'datetime',
    ];

    // ── Relationships ──────────────────────────────────────────────────
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function topups()
    {
        return $this->hasMany(Topup::class);
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    public function refunds()
    {
        return $this->hasMany(Refund::class);
    }

    // ── State helpers ──────────────────────────────────────────────────
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isUsable(): bool
    {
        // Wallet must be active to spend or top up. Frozen/suspended/closed
        // wallets reject all balance-changing operations.
        return $this->status === 'active' && ! $this->trashed();
    }
}
