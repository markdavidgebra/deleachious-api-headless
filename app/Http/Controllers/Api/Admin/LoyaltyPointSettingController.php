<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyPointSetting;
use App\Models\LoyaltyPoint;
use App\Models\User;
use Illuminate\Http\Request;

class LoyaltyPointSettingController extends Controller
{
    // ── GET current settings ──────────────────────────────
    public function getSettings()
    {
        return response()->json(LoyaltyPointSetting::getSettings());
    }

    // ── UPDATE settings ───────────────────────────────────
    public function updateSettings(Request $request)
    {
        $request->validate([
            'peso_per_point'              => 'sometimes|numeric|min:1',
            'bonus_enabled'               => 'sometimes|boolean',
            'bonus_multiplier'            => 'sometimes|numeric|min:1',
            'bonus_days'                  => 'sometimes|array',
            'bonus_days.*'                => 'in:Monday,Tuesday,Wednesday,Thursday,Friday,Saturday,Sunday',
            'bonus_start_time'            => 'sometimes|nullable|date_format:H:i',
            'bonus_end_time'              => 'sometimes|nullable|date_format:H:i',
            'expiry_enabled'              => 'sometimes|boolean',
            'expiry_days'                 => 'sometimes|integer|min:1',
            'min_purchase'                => 'sometimes|numeric|min:0',
            'max_points_per_transaction'  => 'sometimes|nullable|integer|min:1',
        ]);

        $settings = LoyaltyPointSetting::getSettings();
        $settings->update($request->all());

        return response()->json([
            'message'  => 'Loyalty point settings updated successfully',
            'settings' => $settings,
        ]);
    }

    // ── PREVIEW: how many points for a given amount ───────
    public function previewPoints(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0',
        ]);

        $settings = LoyaltyPointSetting::getSettings();
        $points   = $settings->calculatePoints((float) $request->amount);

        return response()->json([
            'amount'        => $request->amount,
            'points_earned' => $points,
            'is_bonus'      => $settings->bonus_enabled && $settings->isBonusPeriod(),
            'multiplier'    => $settings->bonus_enabled && $settings->isBonusPeriod()
                                ? $settings->bonus_multiplier
                                : 1,
        ]);
    }

    // ── MANUALLY add or deduct points for a member ────────
    public function adjustPoints(Request $request, User $user)
    {
        $request->validate([
            'points'      => 'required|integer',         // positive = add, negative = deduct
            'type'        => 'required|in:bonus,adjustment,expired',
            'description' => 'required|string',          // admin must explain why
        ]);

        // Prevent points going below 0
        if ($user->points + $request->points < 0) {
            return response()->json([
                'message' => 'Cannot deduct more points than the member has.',
                'current_points' => $user->points,
            ], 422);
        }

        // Log the point change
        LoyaltyPoint::create([
            'user_id'     => $user->id,
            'points'      => $request->points,
            'type'        => $request->type,
            'description' => $request->description,
        ]);

        // Update total points
        $user->increment('points', $request->points);

        // Update tier automatically
        $user->updateTier();

        return response()->json([
            'message'        => 'Points adjusted successfully',
            'points_changed' => $request->points,
            'new_total'      => $user->fresh()->points,
            'tier'           => $user->fresh()->loyaltyTier,
        ]);
    }

    // ── EXPIRE old points for all members ─────────────────
    public function expirePoints()
    {
        $settings = LoyaltyPointSetting::getSettings();

        if (! $settings->expiry_enabled) {
            return response()->json([
                'message' => 'Point expiry is currently disabled in settings.',
            ], 422);
        }

        $expiryDate = now()->subDays($settings->expiry_days);

        // Find points older than expiry date that haven't been expired yet
        $oldPoints = LoyaltyPoint::where('type', '!=', 'expired')
            ->where('points', '>', 0)
            ->where('created_at', '<', $expiryDate)
            ->get();

        $totalExpired = 0;

        foreach ($oldPoints as $point) {
            $user = User::find($point->user_id);

            if (! $user) continue;

            // Log expiry
            LoyaltyPoint::create([
                'user_id'     => $point->user_id,
                'points'      => -$point->points,
                'type'        => 'expired',
                'description' => 'Points expired after ' . $settings->expiry_days . ' days',
            ]);

            // Deduct from user
            $user->decrement('points', $point->points);
            $user->updateTier();

            $totalExpired += $point->points;
        }

        return response()->json([
            'message'        => 'Points expiry completed',
            'total_expired'  => $totalExpired,
            'users_affected' => $oldPoints->pluck('user_id')->unique()->count(),
        ]);
    }

    // ── GET points history of a member ────────────────────
    public function pointsHistory(User $user)
    {
        $history = $user->loyaltyPoints()
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'user'          => $user->only(['id', 'name', 'email', 'points']),
            'tier'          => $user->loyaltyTier,
            'total_points'  => $user->points,
            'history'       => $history,
        ]);
    }
}