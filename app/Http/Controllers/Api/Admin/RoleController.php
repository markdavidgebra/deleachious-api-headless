<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Role;
use App\Support\AdminPaginator;
use App\Support\AdminPermissions;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;

class RoleController extends Controller
{
    public function permissions()
    {
        foreach (AdminPermissions::names() as $name) {
            Permission::findOrCreate($name, AdminPermissions::GUARD);
        }

        return response()->json([
            'catalog' => AdminPermissions::catalog(),
            'groups'  => AdminPermissions::grouped(),
        ]);
    }

    public function index(Request $request)
    {
        $query = Role::query()
            ->where('guard_name', AdminPermissions::GUARD)
            ->whereNotIn('name', Admin::concealedRoleNames())
            ->with('permissions')
            ->withCount('users')
            ->orderByDesc('is_system')
            ->orderBy('display_name');

        if (AdminPaginator::requested($request)) {
            $paginator = $query->paginate(AdminPaginator::perPage($request))->withQueryString();
            $paginator->setCollection(
                $paginator->getCollection()->map(fn (Role $role) => $this->serialize($role))
            );

            return response()->json($paginator);
        }

        return response()->json(
            $query->get()->map(fn (Role $role) => $this->serialize($role))
        );
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);

        $role = Role::create([
            'name'          => $this->uniqueSlug($validated['display_name']),
            'display_name'  => $validated['display_name'],
            'guard_name'    => AdminPermissions::GUARD,
            'is_system'     => false,
        ]);

        $role->syncPermissions($validated['permissions']);

        AuditLogService::created('roles', $role, 'Role created: ' . $role->display_name);

        return response()->json($this->serialize($role->load('permissions')->loadCount('users')), 201);
    }

    public function show(Role $role)
    {
        $this->assertVisible($role);

        return response()->json($this->serialize($role->load('permissions')->loadCount('users')));
    }

    public function update(Request $request, Role $role)
    {
        $this->assertVisible($role);

        if ($role->name === 'super_admin') {
            return response()->json([
                'message' => 'The Super Admin role cannot be changed.',
            ], 422);
        }

        $validated = $this->validated($request, $role);

        if (! $role->is_system) {
            $role->display_name = $validated['display_name'];
        }

        $role->save();
        $role->syncPermissions($validated['permissions']);

        AuditLogService::updated('roles', $role, [], 'Role updated: ' . $role->display_name);

        return response()->json($this->serialize($role->load('permissions')->loadCount('users')));
    }

    public function destroy(Role $role)
    {
        $this->assertVisible($role);

        if ($role->name === 'super_admin') {
            return response()->json([
                'message' => 'The Super Admin role cannot be deleted.',
            ], 422);
        }

        if ($role->users()->count() > 0) {
            return response()->json([
                'message' => 'This role is assigned to staff and cannot be deleted.',
            ], 422);
        }

        $name = $role->display_name ?: $role->name;
        AuditLogService::deleted('roles', $role, 'Role deleted: ' . $name);
        $role->delete();

        return response()->json(['message' => 'Role deleted']);
    }

    private function validated(Request $request, ?Role $role = null): array
    {
        $validated = $request->validate([
            'display_name' => [
                $role?->is_system ? 'sometimes' : 'required',
                'string',
                'max:80',
            ],
            'permissions'   => 'required|array|min:1',
            'permissions.*' => ['string', Rule::in(AdminPermissions::names())],
        ]);

        $validated['permissions'] = AdminPermissions::normalize($validated['permissions']);

        foreach ($validated['permissions'] as $name) {
            Permission::findOrCreate($name, AdminPermissions::GUARD);
        }

        return $validated;
    }

    private function uniqueSlug(string $displayName): string
    {
        $base = Str::slug($displayName, '_') ?: 'role';
        $slug = $base;
        $i    = 2;

        while (
            in_array($slug, Admin::concealedRoleNames(), true)
            || Role::query()
                ->where('guard_name', AdminPermissions::GUARD)
                ->where('name', $slug)
                ->exists()
        ) {
            $slug = $base.'_'.$i;
            $i++;
        }

        return $slug;
    }

    private function assertVisible(Role $role): void
    {
        $this->assertAdminGuard($role);

        if ($role->name === 'developer') {
            abort(404);
        }
    }

    private function assertAdminGuard(Role $role): void
    {
        abort_unless($role->guard_name === AdminPermissions::GUARD, 404);
    }

    private function serialize(Role $role): array
    {
        return [
            'id'            => $role->id,
            'name'          => $role->name,
            'display_name'  => $role->display_name ?: $role->name,
            'is_system'     => (bool) $role->is_system,
            'permissions'   => $role->permissions->pluck('name')->values()->all(),
            'users_count'   => $role->users_count ?? $role->users()->count(),
        ];
    }
}
