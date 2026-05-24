<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Services\AuditLogService;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $admin = Admin::where('email', $request->email)->first();

        if (! $admin || ! Hash::check($request->password, $admin->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if (! $admin->is_active) {
            return response()->json(['message' => 'Account is disabled'], 403);
        }

        $token = $admin->createToken('admin_token')->plainTextToken;
        AuditLogService::login($admin);

        return response()->json([
            'admin' => $admin,
            'token' => $token,
        ]);
    }

    public function logout(Request $request)
    {
        AuditLogService::logout($request->user());
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user());
    }
}