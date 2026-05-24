<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyTier;
use Illuminate\Http\Request;

class LoyaltyTierController extends Controller
{
    // GET all tiers
    public function index()
    {
        return response()->json(
            LoyaltyTier::withCount('users')
                ->orderBy('min_points')
                ->get()
        );
    }

    // CREATE a tier
    public function store(Request $request)
    {
        $request->validate([
            'name'        => 'required|string|unique:loyalty_tiers',
            'min_points'  => 'required|integer|min:0',
            'discount'    => 'nullable|numeric|min:0|max:100',
            'badge_color' => 'nullable|string',
        ]);

        $tier = LoyaltyTier::create($request->all());

        return response()->json($tier, 201);
    }

    // GET single tier
    public function show(LoyaltyTier $loyaltyTier)
    {
        return response()->json(
            $loyaltyTier->load('users')
        );
    }

    // UPDATE a tier
    public function update(Request $request, LoyaltyTier $loyaltyTier)
    {
        $request->validate([
            'name'        => 'sometimes|string|unique:loyalty_tiers,name,' . $loyaltyTier->id,
            'min_points'  => 'sometimes|integer|min:0',
            'discount'    => 'nullable|numeric|min:0|max:100',
            'badge_color' => 'nullable|string',
        ]);

        $loyaltyTier->update($request->all());

        return response()->json($loyaltyTier);
    }

    // DELETE a tier
    public function destroy(LoyaltyTier $loyaltyTier)
    {
        // Check if tier has users before deleting
        if ($loyaltyTier->users()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete tier — it still has members assigned to it.',
            ], 422);
        }

        $loyaltyTier->delete();

        return response()->json(['message' => 'Tier deleted successfully']);
    }
}