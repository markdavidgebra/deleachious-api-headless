<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\Reward;
use Illuminate\Http\Request;

class RewardController extends Controller
{
    /**
     * Active, non-expired rewards available for members to redeem.
     */
    public function index(Request $request)
    {
        $rewards = Reward::query()
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
            ]);

        return response()->json([
            'rewards' => $rewards,
            'points'  => (int) ($request->user()->points ?? 0),
        ]);
    }
}
