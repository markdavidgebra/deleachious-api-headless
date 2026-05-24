<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StaffController extends Controller
{
    // GET all staff
    public function index(Request $request)
    {
        $staff = Admin::with('branch')
            ->when($request->branch_id, fn($q) => $q->where('branch_id', $request->branch_id))
            ->when($request->role,      fn($q) => $q->where('role',      $request->role))
            ->orderBy('name')
            ->get();

        return response()->json($staff);
    }

    // CREATE staff
    public function store(Request $request)
    {
        $request->validate([
            'name'      => 'required|string',
            'email'     => 'required|email|unique:admins',
            'password'  => 'required|min:6',
            'role'      => 'required|in:super_admin,admin,staff,cashier',
            'phone'     => 'nullable|string',
            'branch_id' => 'nullable|exists:branches,id',
            'is_active' => 'boolean',
        ]);

        $staff = Admin::create([
            'name'      => $request->name,
            'email'     => $request->email,
            'password'  => $request->password,
            'role'      => $request->role,
            'phone'     => $request->phone,
            'branch_id' => $request->branch_id,
            'is_active' => $request->is_active ?? true,
        ]);

        return response()->json($staff->load('branch'), 201);
    }

    // GET single staff
    public function show(Admin $admin)
    {
        return response()->json($admin->load('branch'));
    }

    // UPDATE staff
    public function update(Request $request, Admin $admin)
    {
        $request->validate([
            'name'      => 'sometimes|string',
            'email'     => 'sometimes|email|unique:admins,email,' . $admin->id,
            'password'  => 'sometimes|min:6',
            'role'      => 'sometimes|in:super_admin,admin,staff,cashier',
            'phone'     => 'nullable|string',
            'branch_id' => 'nullable|exists:branches,id',
            'is_active' => 'boolean',
        ]);

        $data = $request->except('password');

        // Only update password if provided
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $admin->update($data);

        return response()->json($admin->load('branch'));
    }

    // TOGGLE staff active status
    public function toggleStatus(Admin $admin)
    {
        $admin->update(['is_active' => ! $admin->is_active]);

        return response()->json([
            'message'   => 'Staff status updated',
            'is_active' => $admin->is_active,
            'staff'     => $admin->load('branch'),
        ]);
    }

    // DELETE staff
    public function destroy(Admin $admin)
    {
        // Prevent deleting yourself
        if ($admin->id === auth()->id()) {
            return response()->json([
                'message' => 'You cannot delete your own account.',
            ], 422);
        }

        $admin->delete();

        return response()->json(['message' => 'Staff deleted successfully']);
    }
}