<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\LoyaltyPoint;
use Illuminate\Http\Request;
use App\Services\AuditLogService;

class MemberController extends Controller
{
    // GET all members
    public function index()
    {
        $members = User::with('loyaltyTier')
            ->orderByDesc('points')
            ->get();

        return response()->json($members);
    }

    // GET single member with full history
    public function show(User $user)
    {
        return response()->json(
            $user->load(['loyaltyTier', 'loyaltyPoints', 'redemptions.reward'])
        );
    }

    // ADD or DEDUCT points manually
    public function adjustPoints(Request $request, User $user)
    {
        $request->validate([
            'points'      => 'required|integer',
            'type'        => 'required|in:earned,redeemed,expired,bonus',
            'description' => 'nullable|string',
        ]);

        // Log the points
        LoyaltyPoint::create([
            'user_id'     => $user->id,
            'points'      => $request->points,
            'type'        => $request->type,
            'description' => $request->description,
        ]);

        // Update user total points
        $user->increment('points', $request->points);

        // Update tier
        $user->updateTier();

        AuditLogService::log(
            'adjusted',
            'member',
            'Points adjusted for ' . $user->name . ': ' . $request->points . ' points (' . $request->type . ')',
            $user
        );

        return response()->json([
            'message' => 'Points adjusted successfully',
            'user'    => $user->fresh()->load('loyaltyTier'),
        ]);
    }

    // GET points history of a member
    public function pointsHistory(User $user)
    {
        $history = $user->loyaltyPoints()
            ->orderByDesc('created_at')
            ->get();

        return response()->json($history);
    }
}