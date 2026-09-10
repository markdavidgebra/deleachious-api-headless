<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PointItem extends Model
{
    use HasFactory;

    protected $table = 'point_items';

    protected $fillable = [
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

    public function redemptions()
    {
        return $this->hasMany(Redemption::class);
    }
}
