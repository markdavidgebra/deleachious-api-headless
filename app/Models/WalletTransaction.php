<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class WalletTransaction extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'reference_no',
        'wallet_id',
        'transaction_type',
        'direction',
        'amount',
        'balance_before',
        'balance_after',
        'branch_id',
        'source_type',
        'source_id',
        'status',
        'metadata',
        'description',
        'created_by_type',
        'created_by_id',
        'idempotency_key',
    ];

    protected $casts = [
        'amount'         => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after'  => 'decimal:2',
        'metadata'       => 'array',
    ];

    /**
     * Boot a UUID + reference number for every new ledger row.
     * These never change once a row is persisted — they are the
     * permanent public identifier of the transaction.
     */
    protected static function booted(): void
    {
        static::creating(function (self $tx) {
            if (empty($tx->uuid)) {
                $tx->uuid = (string) Str::uuid();
            }

            if (empty($tx->reference_no)) {
                $tx->reference_no = self::generateReferenceNumber($tx->transaction_type);
            }
        });
    }

    // ── Relationships ──────────────────────────────────────────────────
    public function wallet()
    {
        return $this->belongsTo(Wallet::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function source()
    {
        return $this->morphTo();
    }

    public function createdBy()
    {
        return $this->morphTo();
    }

    // ── Reference number generator ─────────────────────────────────────
    public static function generateReferenceNumber(string $type): string
    {
        $prefixes = [
            'topup'      => 'WTX-T',
            'purchase'   => 'WTX-P',
            'refund'     => 'WTX-R',
            'adjustment' => 'WTX-A',
        ];

        $prefix = $prefixes[$type] ?? 'WTX';
        // 14-char ULID-ish suffix gives us 10^14 range per prefix.
        return $prefix . '-' . now()->format('Ymd') . '-' . strtoupper(Str::random(10));
    }

    // ── Immutability guard ─────────────────────────────────────────────
    /**
     * Completed financial transactions must never be edited. We allow
     * `status` transitions only via the WalletService (which uses raw
     * updates inside DB::transaction blocks, bypassing the model save).
     */
    public function save(array $options = [])
    {
        if ($this->exists && $this->status === 'completed' && $this->isDirty()) {
            $allowed = ['updated_at', 'deleted_at'];
            $changed = array_keys($this->getDirty());
            $changed = array_diff($changed, $allowed);

            if (! empty($changed)) {
                throw new \RuntimeException(
                    'Completed wallet transactions are immutable. '
                    .'Tried to modify: '.implode(', ', $changed)
                );
            }
        }

        return parent::save($options);
    }
}
