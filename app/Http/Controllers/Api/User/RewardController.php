<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\Reward;
use Illuminate\Http\Request;

class RewardController extends Controller
{
    /**
     * Active, non-expired rewards available for members to redeem.
     * Includes product-linked rewards (menu items marked redeemable in admin).
     */
    public function index(Request $request)
    {
        $rewards = Reward::query()
            ->with(['product:id,name,description,image,base_price,is_available'])
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhereDate('expires_at', '>=', now()->toDateString());
            })
            // Hide product rewards when the menu item is unavailable.
            ->where(function ($q) {
                $q->whereNull('product_id')
                    ->orWhereHas('product', fn ($p) => $p->where('is_available', true));
            })
            ->orderBy('points_required')
            ->orderBy('name')
            ->get([
                'id',
                'product_id',
                'name',
                'description',
                'points_required',
                'type',
                'discount_value',
                'image',
                'expires_at',
            ])
            ->map(function (Reward $reward) {
                $product = $reward->product;
                $image = $reward->image ?: $product?->image;
                $imageUrl = $product?->image_url
                    ?? ($image ? '/storage/'.ltrim($image, '/') : null);

                return [
                    'id' => $reward->id,
                    'product_id' => $reward->product_id,
                    'name' => $reward->name,
                    'description' => $reward->description,
                    'points_required' => (int) $reward->points_required,
                    'type' => $reward->type,
                    'discount_value' => $reward->discount_value,
                    'image' => $image,
                    'image_url' => $imageUrl,
                    'expires_at' => optional($reward->expires_at)?->toDateString(),
                    'product' => $product ? [
                        'id' => $product->id,
                        'name' => $product->name,
                        'base_price' => $product->base_price,
                        'image_url' => $product->image_url,
                    ] : null,
                ];
            })
            ->values();

        return response()->json([
            'rewards' => $rewards,
            'points'  => (int) ($request->user()->points ?? 0),
        ]);
    }
}
