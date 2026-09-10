<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\PointItem;
use Illuminate\Http\Request;

class RewardController extends Controller
{
    /**
     * Active, non-expired point items members can redeem.
     */
    public function index(Request $request)
    {
        $items = PointItem::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhereDate('expires_at', '>=', now()->toDateString());
            })
            ->orderBy('points_required')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'description',
                'points_required',
                'type',
                'discount_value',
                'image',
                'expires_at',
            ])
            ->map(function (PointItem $item) {
                $image = $item->image;
                $imageUrl = $image ? '/storage/'.ltrim($image, '/') : null;

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'description' => $item->description,
                    'points_required' => (int) $item->points_required,
                    'type' => $item->type,
                    'discount_value' => $item->discount_value,
                    'image' => $image,
                    'image_url' => $imageUrl,
                    'expires_at' => optional($item->expires_at)?->toDateString(),
                ];
            })
            ->values();

        return response()->json([
            'items'  => $items,
            'points' => (int) ($request->user()->points ?? 0),
        ]);
    }
}
