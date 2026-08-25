<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Reward;

class ProductRewardSyncService
{
    /**
     * Keep a linked Reward row in sync when admin marks a product redeemable.
     * Expo / QR redeem continue to use reward_id.
     */
    public function sync(Product $product): ?Reward
    {
        $product->refresh();

        $existing = Reward::query()
            ->where('product_id', $product->id)
            ->first();

        $redeemable = (bool) $product->is_redeemable
            && (int) ($product->points_required ?? 0) > 0;

        if (! $redeemable) {
            if ($existing) {
                $existing->update(['is_active' => false]);
            }

            return $existing;
        }

        $payload = [
            'product_id' => $product->id,
            'name' => $product->name,
            'description' => $product->description,
            'points_required' => (int) $product->points_required,
            'type' => 'free_item',
            'discount_value' => null,
            'image' => $product->image,
            'is_active' => (bool) $product->is_available,
            'expires_at' => null,
        ];

        if ($existing) {
            $existing->update($payload);

            return $existing->fresh();
        }

        return Reward::create($payload);
    }
}
