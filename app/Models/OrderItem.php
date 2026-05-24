<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'product_variant_id',
        'product_name',
        'variant_name',
        'unit_price',
        'quantity',
        'subtotal',
        'notes',
    ];

    protected $casts = [
        'unit_price' => 'float',
        'subtotal'   => 'float',
        'quantity'   => 'integer',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function addons()
    {
        return $this->hasMany(OrderItemAddon::class);
    }
}