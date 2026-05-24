<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Branch extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'address',
        'city',
        'phone',
        'email',
        'opening_time',
        'closing_time',
        'is_active',
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'latitude'  => 'float',
        'longitude' => 'float',
    ];

    // A branch has many staff
    public function staff()
    {
        return $this->hasMany(Admin::class);
    }

    // A branch has many orders
    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}