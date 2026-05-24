<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\LoyaltyTier;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name'     => 'required|string',
            'email'    => 'required|email|unique:users',
            'password' => 'required|min:6',
            'phone'    => 'nullable|string',
        ]);

        // Default everyone to the lowest tier (Bronze / min_points = 0)
        $defaultTier = LoyaltyTier::orderBy('min_points')->first();

        $user = User::create([
            'name'            => $request->name,
            'email'           => $request->email,
            'password'        => $request->password,
            'phone'           => $request->phone,
            'points'          => 0,
            'loyalty_tier_id' => $defaultTier?->id,
        ]);

        $token = $user->createToken('user_token')->plainTextToken;

        return response()->json([
            'user'  => $user->fresh(),
            'token' => $token,
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // Keep tier in sync with current points on every login.
        $user->updateTier();

        $token = $user->createToken('user_token')->plainTextToken;

        return response()->json([
            'user'  => $user->fresh(),
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->fresh());
    }
}