<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'user_id',
        'branch_id',
        'handled_by',
        'type',
        'status',
        'subtotal',
        'discount',
        'total',
        'points_earned',
        'points_used',
        'notes',
        'completed_at',
    ];

    protected $casts = [
        'subtotal' => 'float',
        'discount' => 'float',
        'total' => 'float',
        'points_earned' => 'integer',
        'points_used' => 'integer',
        'completed_at' => 'datetime',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function handledBy()
    {
        return $this->belongsTo(Admin::class, 'handled_by');
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function transaction()
    {
        return $this->hasOne(Transaction::class);
    }

    // QR Code relationship
    public function qrCode()
    {
        return $this->morphOne(QrCode::class, 'qrable');
    }

    // Auto generate order number
    public static function generateOrderNumber(): string
    {
        $date = now()->format('Ymd');
        $count = self::whereDate('created_at', today())->count() + 1;

        return 'ORD-' . $date . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }
}