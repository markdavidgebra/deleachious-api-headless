<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'sent_by',
        'user_id',
        'loyalty_tier_id',
        'title',
        'body',
        'type',
        'data',
        'target',
        'sent_count',
        'read_at',
    ];

    protected $casts = [
        'data'    => 'array',
        'read_at' => 'datetime',
    ];

    // Who sent it
    public function sentBy()
    {
        return $this->belongsTo(Admin::class, 'sent_by');
    }

    // Specific user it was sent to
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Specific tier it was sent to
    public function loyaltyTier()
    {
        return $this->belongsTo(LoyaltyTier::class);
    }

    // Check if read
    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}