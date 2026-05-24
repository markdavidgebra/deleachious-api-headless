<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_number',
        'order_id',
        'user_id',
        'payment_method',
        'status',
        'amount',
        'change',
        'proof',
        'paid_at',
    ];

    protected $casts = [
        'amount'  => 'float',
        'change'  => 'float',
        'paid_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Auto generate reference number
    public static function generateReferenceNumber(): string
    {
        $date  = now()->format('Ymd');
        $count = self::whereDate('created_at', today())->count() + 1;

        return 'TXN-' . $date . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }
}