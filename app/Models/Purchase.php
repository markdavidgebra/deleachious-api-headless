<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Purchase extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'reference_no',
        'wallet_id',
        'user_id',
        'branch_id',
        'order_id',
        'amount',
        'currency',
        'status',
        'qr_token',
        'qr_expires_at',
        'wallet_transaction_id',
        'idempotency_key',
        'metadata',
        'paid_at',
    ];

    protected $casts = [
        'amount'        => 'decimal:2',
        'metadata'      => 'array',
        'qr_expires_at' => 'datetime',
        'paid_at'       => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $purchase) {
            if (empty($purchase->reference_no)) {
                $purchase->reference_no = self::generateReferenceNumber();
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

    public function order()
    {
        return $this->belongsTo(Order::class);
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
        return 'PUR-' . now()->format('Ymd') . '-' . strtoupper(Str::random(8));
    }

    /**
     * Whether the dynamic QR for this purchase is still scannable.
     * The QR is single-use and expires after wallet_settings.qr_ttl_seconds.
     */
    public function qrIsValid(): bool
    {
        return $this->status === 'pending'
            && $this->qr_token
            && $this->qr_expires_at
            && $this->qr_expires_at->isFuture();
    }
}
