<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Role;
use App\Support\AdminPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Services\AuditLogService;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        return $this->attempt($request, developerOnly: false);
    }

    public function developerLogin(Request $request)
    {
        return $this->attempt($request, developerOnly: true);
    }

    private function attempt(Request $request, bool $developerOnly)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $admin = Admin::where('email', $request->email)->first();

        if (! $admin || ! Hash::check($request->password, $admin->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $isDeveloper = $admin->isDeveloper();

        if ($developerOnly !== $isDeveloper) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if (! $admin->is_active) {
            return response()->json(['message' => 'Account is disabled'], 403);
        }

        $admin->load('branch');

        if ($admin->requiresBranch() && ! $admin->branch_id) {
            return response()->json([
                'message' => 'This account is not assigned to a branch yet.',
            ], 403);
        }

        if ($admin->isBranchScoped() && $admin->branch && ! $admin->branch->is_active) {
            return response()->json([
                'message' => 'This branch is currently closed.',
            ], 403);
        }

        $this->ensureSpatieRole($admin);

        $token = $admin->createToken('admin_token')->plainTextToken;
        AuditLogService::login($admin);

        return response()->json([
            'admin' => $admin->fresh(['branch'])->toAuthArray(),
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
        $admin = $request->user();

        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $this->ensureSpatieRole($admin);

        return response()->json($admin->load('branch')->toAuthArray());
    }

    public function updateProfile(Request $request)
    {
        /** @var Admin $admin */
        $admin = $request->user();

        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'name'  => 'required|string|max:120',
            'email' => 'required|email|max:190|unique:admins,email,' . $admin->id,
        ]);

        $old = $admin->only(['name', 'email']);
        $admin->update($validated);

        AuditLogService::updated(
            'auth',
            $admin,
            $old,
            'Profile updated: ' . $admin->name,
        );

        return response()->json([
            'message' => 'Profile updated',
            'admin'   => $admin->fresh()->toAuthArray(),
        ]);
    }

    public function changePassword(Request $request)
    {
        /** @var Admin $admin */
        $admin = $request->user();

        if (! $admin instanceof Admin) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'password' => 'required|string|min:6',
        ]);

        $admin->update(['password' => $request->password]);

        return response()->json(['message' => 'Password updated successfully']);
    }

    private function ensureSpatieRole(Admin $admin): void
    {
        if (! $admin->role) {
            return;
        }

        try {
            $exists = Role::query()
                ->where('name', $admin->role)
                ->where('guard_name', AdminPermissions::GUARD)
                ->exists();

            if ($exists) {
                $admin->syncNamedRole($admin->role);
            }
        } catch (\Throwable) {
            // Permissions tables may not be migrated yet.
        }
    }
}