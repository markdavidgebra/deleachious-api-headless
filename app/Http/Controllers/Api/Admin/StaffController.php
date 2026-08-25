<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Support\AdminPaginator;
use App\Support\AdminPermissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class StaffController extends Controller
{
    public function index(Request $request)
    {
        $scoped = Admin::query()
            ->notConcealed()
            ->when($request->user(), fn ($q) => $q->where('id', '!=', $request->user()->id));

        $actor = $request->user();
        if ($actor instanceof Admin && $actor->isBranchScoped()) {
            $scoped->where('branch_id', $actor->branch_id);
        }

        $stats = [
            'total'    => (clone $scoped)->count(),
            'active'   => (clone $scoped)->where('is_active', true)->count(),
            'inactive' => (clone $scoped)->where('is_active', false)->count(),
            'admins'   => (clone $scoped)->where('role', 'admin')->count(),
        ];

        $query = (clone $scoped)
            ->with(['branch', 'roles'])
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->branch_id))
            ->when($request->filled('role'),      fn ($q) => $q->where('role',      $request->role))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->search.'%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term)
                        ->orWhere('phone', 'like', $term);
                });
            })
            ->orderBy('name');

        if (AdminPaginator::requested($request)) {
            $paginator = $query->paginate(AdminPaginator::perPage($request))->withQueryString();
            $paginator->setCollection(
                $paginator->getCollection()->map(fn (Admin $admin) => $this->serialize($admin))
            );
            $payload = $paginator->toArray();
            $payload['stats'] = $stats;

            return response()->json($payload);
        }

        return response()->json(
            $query->get()->map(fn (Admin $admin) => $this->serialize($admin))
        );
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'      => 'required|string',
            'email'     => 'required|email|unique:admins',
            'password'  => 'required|min:6',
            'role'      => ['required', 'string', $this->roleRule()],
            'phone'     => 'nullable|string',
            'branch_id' => 'nullable|exists:branches,id',
            'is_active' => 'boolean',
        ]);

        $this->assertCanAssign($request->role);
        $branchId = $this->resolvedBranchId($request);
        $this->assertBranchForRole($request->role, $branchId);

        $staff = Admin::create([
            'name'      => $request->name,
            'email'     => $request->email,
            'password'  => $request->password,
            'role'      => $request->role,
            'phone'     => $request->phone,
            'branch_id' => $branchId,
            'is_active' => $request->is_active ?? true,
        ]);

        $staff->syncNamedRole($request->role);

        return response()->json($this->serialize($staff->load(['branch', 'roles'])), 201);
    }

    public function show(Admin $admin)
    {
        $this->assertVisible($admin);

        return response()->json($this->serialize($admin->load(['branch', 'roles'])));
    }

    public function update(Request $request, Admin $admin)
    {
        $this->assertVisible($admin);

        if ($this->isProtected($request, $admin)) {
            return response()->json([
                'message' => 'The Super Admin account cannot be edited from Staff.',
            ], 422);
        }

        $request->validate([
            'name'      => 'sometimes|string',
            'email'     => 'sometimes|email|unique:admins,email,'.$admin->id,
            'password'  => 'sometimes|min:6',
            'role'      => ['sometimes', 'string', $this->roleRule()],
            'phone'     => 'nullable|string',
            'branch_id' => 'nullable|exists:branches,id',
            'is_active' => 'boolean',
        ]);

        $data = $request->except('password');
        $data['branch_id'] = $this->resolvedBranchId($request, $admin);

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        if ($request->filled('role')) {
            $this->assertCanAssign($request->role);
        }

        $this->assertBranchForRole(
            $request->input('role', $admin->role),
            $data['branch_id']
        );

        $admin->update($data);

        if ($request->filled('role')) {
            $admin->syncNamedRole($request->role);
        }

        return response()->json($this->serialize($admin->load(['branch', 'roles'])));
    }

    public function toggleStatus(Request $request, Admin $admin)
    {
        $this->assertVisible($admin);

        if ($this->isProtected($request, $admin)) {
            return response()->json([
                'message' => 'The Super Admin account cannot be deactivated.',
            ], 422);
        }

        $admin->update(['is_active' => ! $admin->is_active]);

        return response()->json([
            'message'   => 'Staff status updated',
            'is_active' => $admin->is_active,
            'staff'     => $this->serialize($admin->load(['branch', 'roles'])),
        ]);
    }

    public function destroy(Request $request, Admin $admin)
    {
        $this->assertVisible($admin);

        if ($this->isProtected($request, $admin)) {
            return response()->json([
                'message' => 'The Super Admin account cannot be deleted.',
            ], 422);
        }

        $admin->delete();

        return response()->json(['message' => 'Staff deleted successfully']);
    }

    private function isConcealed(Admin $admin): bool
    {
        return $admin->isDeveloper();
    }

    private function assertVisible(Admin $admin): void
    {
        if ($this->isConcealed($admin)) {
            abort(404);
        }

        $actor = request()->user();
        if ($actor instanceof Admin && $actor->isBranchScoped()
            && (int) $admin->branch_id !== (int) $actor->branch_id) {
            abort(404);
        }
    }

    private function resolvedBranchId(Request $request, ?Admin $existing = null): ?int
    {
        $actor = $request->user();
        if ($actor instanceof Admin && $actor->isBranchScoped()) {
            return (int) $actor->branch_id;
        }

        if ($request->exists('branch_id')) {
            return $request->filled('branch_id') ? (int) $request->branch_id : null;
        }

        return $existing?->branch_id ? (int) $existing->branch_id : null;
    }

    private function assertBranchForRole(string $roleName, ?int $branchId): void
    {
        if (in_array($roleName, ['staff', 'cashier'], true) && ! $branchId) {
            abort(response()->json([
                'message' => 'Assign this person to a branch.',
            ], 422));
        }
    }

    private function isProtected(Request $request, Admin $admin): bool
    {
        return $admin->isSuperAdmin() || $admin->id === $request->user()?->id;
    }

    private function roleRule()
    {
        return Rule::exists('roles', 'name')->where(
            fn ($q) => $q
                ->where('guard_name', AdminPermissions::GUARD)
                ->whereNotIn('name', Admin::concealedRoleNames())
        );
    }

    private function assertCanAssign(string $roleName): void
    {
        if (in_array($roleName, Admin::concealedRoleNames(), true)) {
            abort(response()->json([
                'message' => 'That role cannot be assigned from Staff.',
            ], 422));
        }
    }

    private function serialize(Admin $admin): array
    {
        $assigned = $admin->relationLoaded('roles')
            ? $admin->roles->first()
            : $admin->roles()->first();

        $data = $admin->toArray();
        unset($data['roles']);
        $data['role_label'] = $assigned?->display_name
            ?? str_replace('_', ' ', (string) $admin->role);

        return $data;
    }
}
