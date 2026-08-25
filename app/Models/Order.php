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

    public function branch()
    {
        return $this->belongsTo(Branch::class);
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

    /**
     * One-time counter QR. Staff scan this to mark the order served / picked up.
     */
    public function ensurePickupQr(?int $expiresInMinutes = 120): QrCode
    {
        $existing = QrCode::query()
            ->where('qrable_type', self::class)
            ->where('qrable_id', $this->id)
            ->where('purpose', 'order_pickup')
            ->latest('id')
            ->first();

        if ($existing && $existing->isValid()) {
            return $existing;
        }

        if ($existing) {
            $existing->update(['is_active' => false]);
        }

        return QrCode::create([
            'code'        => QrCode::generateCode(),
            'type'        => 'order',
            'qrable_type' => self::class,
            'qrable_id'   => $this->id,
            'purpose'     => 'order_pickup',
            'is_active'   => true,
            'max_scans'   => 1,
            'expires_at'  => now()->addMinutes($expiresInMinutes ?? 120),
        ]);
    }

    // Auto generate order number
    public static function generateOrderNumber(): string
    {
        $date = now()->format('Ymd');
        $count = self::whereDate('created_at', today())->count() + 1;

        return 'ORD-' . $date . '-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }
}