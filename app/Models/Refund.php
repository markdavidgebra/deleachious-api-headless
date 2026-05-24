<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Refund extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'reference_no',
        'wallet_id',
        'user_id',
        'refundable_type',
        'refundable_id',
        'amount',
        'currency',
        'status',
        'method',
        'reason',
        'admin_notes',
        'reviewed_by',
        'reviewed_at',
        'wallet_transaction_id',
        'gateway_refund_id',
        'metadata',
        'completed_at',
    ];

    protected $casts = [
        'amount'       => 'decimal:2',
        'metadata'     => 'array',
        'reviewed_at'  => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $refund) {
            if (empty($refund->reference_no)) {
                $refund->reference_no = self::generateReferenceNumber();
            }
        });
    }

    // ── Relationships ──────────────────────────────────────────────────
    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function refundable()
    {
        return $this->morphTo();
    }

    public function reviewer()
    {
        return $this->belongsTo(Admin::class, 'reviewed_by');
    }

    public function transaction()
    {
        return $this->belongsTo(WalletTransaction::class, 'wallet_transaction_id');
    }

    // ── Helpers ────────────────────────────────────────────────────────
    public static function generateReferenceNumber(): string
    {
        return 'REF-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
    }
}
