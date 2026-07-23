<?php

namespace App\Http\Controllers\Api\User;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

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

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => $request->password,
            'phone'    => $request->phone,
            'points'   => 0,
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

        if (str_ends_with((string) $user->email, '@deleted.invalid')) {
            return response()->json(['message' => 'This account has been deleted.'], 403);
        }

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

    // PATCH /user/profile
    public function updateProfile(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name'  => 'sometimes|required|string|max:120',
            'email' => 'sometimes|required|email|max:190|unique:users,email,' . $user->id,
            'phone' => 'nullable|string|max:40',
        ]);

        $user->update($validated);

        return response()->json([
            'message' => 'Profile updated',
            'user' => $user->fresh(),
        ]);
    }

    // POST /user/change-password
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:6|confirmed',
        ]);

        /** @var User $user */
        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        $user->update([
            'password' => $request->password,
        ]);

        return response()->json([
            'message' => 'Password updated successfully',
        ]);
    }

    // POST /user/avatar  (multipart form-data, field: "avatar")
    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,jpg,png,webp|max:4096',
        ]);

        /** @var User $user */
        $user = $request->user();

        if ($user->avatar_path && Storage::disk('public')->exists($user->avatar_path)) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->update(['avatar_path' => $path]);

        return response()->json([
            'message' => 'Profile picture updated',
            'user' => $user->fresh(),
        ]);
    }

    // DELETE /user/avatar
    public function deleteAvatar(Request $request)
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->avatar_path) {
            if (Storage::disk('public')->exists($user->avatar_path)) {
                Storage::disk('public')->delete($user->avatar_path);
            }
            $user->update(['avatar_path' => null]);
        }

        return response()->json([
            'message' => 'Profile picture removed',
            'user' => $user->fresh(),
        ]);
    }
}