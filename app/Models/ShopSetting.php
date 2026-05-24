<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ShopSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_name',
        'tagline',
        'address',
        'city',
        'phone',
        'email',
        'opening_time',
        'closing_time',
        'currency',
        'timezone',
        'logo_path',
        'font_family',
        'sidebar_bg',
        'header_bg',
        'content_bg',
        'theme_mode',
        'nav_layout',
    ];

    // Expose a ready-to-use absolute URL on every JSON response
    protected $appends = ['logo_url'];

    // Singleton-style accessor — there is only ever one row
    public static function getSettings(): self
    {
        return self::firstOrCreate([], [
            'shop_name'    => 'Daleachious Coffee Shop',
            'tagline'      => 'Brewed with love, served with care',
            'address'      => '123 Coffee Street, Poblacion',
            'city'         => 'Davao City',
            'phone'        => '082-123-4567',
            'email'        => 'hello@daleachious.com',
            'opening_time' => '07:00',
            'closing_time' => '22:00',
            'currency'     => '₱',
            'timezone'     => 'Asia/Manila',
            'font_family'  => 'Inter',
            'sidebar_bg'   => '#402218',
            'header_bg'    => '#ffffff',
            'content_bg'   => '#f7f7f7',
            'theme_mode'   => 'lighter',
            'nav_layout'   => 'sidebar',
        ]);
    }

    // Return a host-less, absolute path like "/storage/logos/abc.png".
    // The frontend (or its dev proxy) resolves the host, which keeps things
    // working whether Laravel is served via Laragon, `php artisan serve`,
    // or behind a reverse proxy in production.
    public function getLogoUrlAttribute(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }

        return '/storage/' . ltrim($this->logo_path, '/');
    }
}
