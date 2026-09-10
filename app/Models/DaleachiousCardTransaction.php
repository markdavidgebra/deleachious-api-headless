<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DaleachiousCardTransaction extends Model
{
    public const TYPE_TOPUP = 'topup';
    public const TYPE_PURCHASE = 'purchase';

    public const STATUS_PENDING = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'daleachious_card_id',
        'user_id',
        'type',
        'amount',
        'balance_before',
        'balance_after',
        'status',
        'reference',
        'payment_method',
        'description',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(DaleachiousCard::class, 'daleachious_card_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
