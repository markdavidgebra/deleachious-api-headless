<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Topup extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'reference_no',
        'wallet_id',
        'user_id',
        'channel',
        'currency',
        'amount',
        'status',
        'gateway',
        'gateway_intent_id',
        'gateway_source_id',
        'gateway_payment_id',
        'checkout_url',
        'branch_id',
        'idempotency_key',
        'wallet_transaction_id',
        'metadata',
        'failure_reason',
        'paid_at',
    ];

    protected $casts = [
        'amount'   => 'decimal:2',
        'metadata' => 'array',
        'paid_at'  => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $topup) {
            if (empty($topup->reference_no)) {
                $topup->reference_no = self::generateReferenceNumber();
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

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function transaction()
    {
        return $this->belongsTo(WalletTransaction::class, 'wallet_transaction_id');
    }

    public function refunds()
    {
        return $this->morphMany(Refund::class, 'refundable');
    }

    // ── Helpers ────────────────────────────────────────────────────────
    public static function generateReferenceNumber(): string
    {
        return 'TOP-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
    }

    public function isFinal(): bool
    {
        return in_array($this->status, ['succeeded', 'failed', 'refunded'], true);
    }
}
