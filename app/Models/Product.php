<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'description',
        'image',
        'base_price',
        'is_available',
        'is_featured',
        'sort_order',
    ];

    protected $casts = [
        'base_price'   => 'float',
        'is_available' => 'boolean',
        'is_featured'  => 'boolean',
    ];

    // Expose a ready-to-use absolute URL on every JSON response
    protected $appends = ['image_url'];

    // Return a host-less, absolute path like "/storage/products/abc.png".
    // The frontend (or its dev proxy) resolves the host, mirroring how
    // ShopSetting exposes `logo_url`.
    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image) {
            return null;
        }

        return '/storage/' . ltrim($this->image, '/');
    }

    // A product belongs to a category
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    // A product has many variants
    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    // A product has many add-ons
    public function addons()
    {
        return $this->hasMany(ProductAddon::class);
    }
}