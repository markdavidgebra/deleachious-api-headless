<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

class QrCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'type',
        'qrable_type',
        'qrable_id',
        'purpose',
        'is_active',
        'scan_count',
        'max_scans',
        'expires_at',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'scan_count' => 'integer',
        'max_scans'  => 'integer',
        'expires_at' => 'datetime',
    ];

    // Polymorphic — links to User or Order
    public function qrable()
    {
        return $this->morphTo();
    }

    // Scans log
    public function scans()
    {
        return $this->hasMany(QrScan::class);
    }

    // Generate a unique QR code string
    public static function generateCode(): string
    {
        do {
            $code = strtoupper(Str::random(12));
        } while (self::where('code', $code)->exists());

        return $code;
    }

    // Check if QR code is still valid
    public function isValid(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->expires_at && now()->isAfter($this->expires_at)) {
            return false;
        }

        if ($this->max_scans && $this->scan_count >= $this->max_scans) {
            return false;
        }

        return true;
    }

    // Check if expired
    public function isExpired(): bool
    {
        return $this->expires_at && now()->isAfter($this->expires_at);
    }
}