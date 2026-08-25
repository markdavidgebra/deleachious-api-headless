<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Reward extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'name',
        'description',
        'points_required',
        'type',
        'discount_value',
        'image',
        'is_active',
        'expires_at',
    ];

    protected $casts = [
        'is_active'      => 'boolean',
        'discount_value' => 'float',
        'expires_at'     => 'date',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function redemptions()
    {
        return $this->hasMany(Redemption::class);
    }
}