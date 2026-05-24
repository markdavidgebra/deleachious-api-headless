<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class OrderItemAddon extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_item_id',
        'product_addon_id',
        'addon_name',
        'price',
    ];

    protected $casts = [
        'price' => 'float',
    ];

    public function orderItem()
    {
        return $this->belongsTo(OrderItem::class);
    }
}